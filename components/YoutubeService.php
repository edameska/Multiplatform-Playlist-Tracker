<?php
namespace app\components;

use Yii;
use yii\base\Component;

class YoutubeService extends Component
{
    public string $clientId;
    public string $clientSecret;
    public string $redirectUri;

    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API_BASE  = 'https://www.googleapis.com/youtube/v3';

    // ================= OAuth ================= //
    public function getAuthUrl(): string
    {
        $scope = urlencode('https://www.googleapis.com/auth/youtube.readonly');
        return self::AUTH_URL
            . "?client_id={$this->clientId}"
            . "&response_type=code"
            . "&redirect_uri=" . urlencode($this->redirectUri)
            . "&scope={$scope}"
            . "&access_type=offline"
            . "&prompt=consent";
    }

    public function exchangeCodeForToken(string $code): array
    {
        $post = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
        ]);

        $opts = ['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
            'ignore_errors' => true,
        ]];

        $response = file_get_contents(self::TOKEN_URL, false, stream_context_create($opts));
        $data = json_decode($response, true) ?? [];

        if (!empty($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;
        }

        return $data;
    }

    public function refreshAccessToken(): array
    {
        if (!$this->refreshToken) {
            Yii::error("No refresh token set", __METHOD__);
            return [];
        }

        $post = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post]];
        $response = file_get_contents(self::TOKEN_URL, false, stream_context_create($opts));
        $data = json_decode($response, true) ?? [];

        if (!empty($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            Yii::info("YouTube access token refreshed", __METHOD__);
        }

        return $data;
    }

    public function setAccessToken(string $token, ?string $refreshToken = null): void
    {
        $this->accessToken = $token;
        if ($refreshToken) $this->refreshToken = $refreshToken;
    }

    // ================= API Calls ================= //
    private function apiGet(string $endpoint, array $params = [], bool $retry = true): array
{
    if (!$this->accessToken) {
        Yii::error("No access token set for YouTube API call", __METHOD__);
        return [];
    }

    $url = self::API_BASE . $endpoint . '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$this->accessToken}",
            "Accept: application/json",
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        Yii::error("cURL error while calling YouTube API: $curlError", __METHOD__);
        return [];
    }

    $data = json_decode($response, true) ?? [];

    Yii::info("YouTube API GET $url returned HTTP $httpCode: " . ($response ?: 'empty'), __METHOD__);

    // Retry once if unauthorized
    if ($retry && $httpCode === 401 && $this->refreshToken) {
        Yii::info("Access token expired, refreshing...", __METHOD__);
        $tokens = $this->refreshAccessToken();
        if (!empty($tokens['access_token'])) {
            return $this->apiGet($endpoint, $params, false); // retry once
        } else {
            Yii::error("Failed to refresh YouTube token", __METHOD__);
        }
    }

    return $data;
}


    // ================= Playlists ================= //
    public function getPlaylists(int $maxResults = 50): array
    {
        $data = $this->apiGet('/playlists', [
            'part' => 'snippet,contentDetails',
            'mine' => 'true',
            'maxResults' => $maxResults,
        ]);

        $items = $data['items'] ?? [];
        $playlists = [];

        foreach ($items as $p) {
            if (empty($p['id'])) {
                Yii::warning("Skipped playlist with missing ID: " . json_encode($p), __METHOD__);
                continue;
            }
            $title = $p['snippet']['title'] ?? 'Untitled';
            $itemCount = $p['contentDetails']['itemCount'] ?? 0;

            if ($title === 'Untitled' && $itemCount === 0) {
                Yii::warning("Skipped inaccessible playlist: " . json_encode($p), __METHOD__);
                continue;
            }

            $playlists[] = [
                'id' => $p['id'],
                'title' => $title,
                'description' => $p['snippet']['description'] ?? '',
                'itemCount' => $itemCount,
            ];
        }

        return $playlists;
    }

    public function getPlaylistItems(string $playlistId, int $maxResults = 50, string $pageToken = ''): array
    {
        $params = [
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => $maxResults,
        ];
        if ($pageToken) $params['pageToken'] = $pageToken;

        $data = $this->apiGet('/playlistItems', $params);

        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            $items[] = [
                'id' => $item['snippet']['resourceId']['videoId'] ?? null,
                'title' => $item['snippet']['title'] ?? 'Untitled',
                'channel' => $item['snippet']['videoOwnerChannelTitle'] ?? 'Unknown',
                'url' => 'https://www.youtube.com/watch?v=' . ($item['snippet']['resourceId']['videoId'] ?? ''),
                'position' => $item['snippet']['position'] ?? 0,
            ];
        }

        Yii::info("Fetched " . count($items) . " items from playlist $playlistId, nextPageToken: " . ($data['nextPageToken'] ?? 'none'), __METHOD__);
        return [
            'items' => $items,
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    // ================= User Channel ================= //
    public function getMyChannel(): array
    {
        $data = $this->apiGet('/channels', [
            'part' => 'id,snippet',
            'mine' => 'true',
        ]);

        $channel = $data['items'][0] ?? [];
        return [
            'id' => $channel['id'] ?? null,
            'title' => $channel['snippet']['title'] ?? 'Untitled',
        ];
    }

    // ============== Controller helper ============== //
    public function getClient(): self
    {
        return $this;
    }

    public function createAuthUrl(): string
    {
        return $this->getAuthUrl();
    }
}
