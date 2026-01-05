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

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post,
                'ignore_errors' => true,
            ],
        ];

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
    private function apiGet(string $endpoint, array $params = []): array
    {
        if (!$this->accessToken) return [];

        $url = self::API_BASE . $endpoint . '?' . http_build_query(array_merge($params, ['access_token' => $this->accessToken]));
        $response = @file_get_contents($url);
        return json_decode($response, true) ?? [];
    }

    /**
     * Return normalized playlists for a channel
     */
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

        // Skip playlists that are completely inaccessible (no title & 0 items)
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
        return $items;
    }

    /**
     * Fetch authenticated user's channel info
     */
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
