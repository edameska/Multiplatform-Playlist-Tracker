<?php
namespace app\components;

use Yii;
use yii\base\Component;

class SpotifyService extends Component
{
    public string $clientId;
    public string $clientSecret;
    public string $redirectUri;

    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    const AUTH_URL  = 'https://accounts.spotify.com/authorize';
    const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    const API_BASE  = 'https://api.spotify.com/v1';

    // ================= OAuth ================= //

    public function getAuthUrl(): string
    {
        $scope = urlencode('playlist-read-private playlist-read-collaborative user-read-email');
        return self::AUTH_URL 
            . "?client_id={$this->clientId}"
            . "&response_type=code"
            . "&redirect_uri=" . urlencode($this->redirectUri)
            . "&scope={$scope}";
    }

    public function exchangeCodeForToken(string $code): array
    {
        $post = http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri
        ]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Basic " . base64_encode("{$this->clientId}:{$this->clientSecret}") .
                             "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post,
                'ignore_errors' => true
            ]
        ];

        $response = file_get_contents(self::TOKEN_URL, false, stream_context_create($opts));
        $data = json_decode($response, true);
        if (!is_array($data)) {
            Yii::error("Invalid JSON from Spotify API: $response", __METHOD__);
            $data = [];
        }

        if (isset($data['access_token'])) {
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
            'refresh_token' => $this->refreshToken
        ]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Basic " . base64_encode("{$this->clientId}:{$this->clientSecret}") .
                            "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post,
                'ignore_errors' => true
            ]
        ];

        $response = file_get_contents(self::TOKEN_URL, false, stream_context_create($opts));

        if ($response === false) {
            Yii::error("Failed to call Spotify token endpoint", __METHOD__);
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            Yii::error("Invalid JSON response from Spotify token endpoint: $response", __METHOD__);
            return [];
        }

        // Update in-memory tokens
        if (!empty($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            Yii::info("Access token refreshed successfully", __METHOD__);
        } else {
            Yii::error("No access_token in response: " . json_encode($data), __METHOD__);
            return [];
        }

        if (!empty($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
            Yii::info("Refresh token updated", __METHOD__);
        }

        // Push new token to DB if user is logged in
        if ($userId = Yii::$app->user->id) {
            $account = \app\models\ApiAccount::findOne([
                'user_id' => $userId,
                'platform' => 'spotify'
            ]);

            if ($account) {
                $account->access_token = $this->accessToken;
                if (!empty($data['refresh_token'])) {
                    $account->refresh_token = $this->refreshToken;
                }
                $account->expires_at = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
                $account->save();
                Yii::info("Spotify tokens updated in DB for user {$userId}", __METHOD__);
            }
        }

        return $data;
    }

    public function setRefreshToken(string $token): void
        {
            $this->refreshToken = $token;
        }

    public function setAccessToken(string $token, ?string $refreshToken = null): void
    {
        $this->accessToken = $token;
        if ($refreshToken) {
            $this->refreshToken = $refreshToken;
        }
    }

    // ================= API Calls ================= //
    private function apiGet(string $endpoint, int $maxRetries = 1): string
{
    $retry = 0;

    do {
        if (!$this->accessToken) {
            Yii::error("No access token set", __METHOD__);
            return json_encode([]);
        }

        $url = self::API_BASE . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$this->accessToken}"]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $curlInfo = curl_getinfo($ch);
        $httpCode = $curlInfo['http_code'] ?? 0;
        curl_close($ch);

        // Token refresh logic if 401
        if ($httpCode === 401 && $retry < $maxRetries) {
            $this->refreshAccessToken();
            $retry++;
            continue;
        }

        if ($httpCode !== 200) {
            Yii::error("Spotify API GET failed ($httpCode): " . substr($response ?? '', 0, 2000), __METHOD__);
            return json_encode([]);
        }

        return $response; // <-- JSON string returned

    } while ($retry <= $maxRetries);

    return json_encode([]);
}



    public function getUserPlaylists(int $limit = 50, int $offset = 0): string
{
    return $this->apiGet("/me/playlists?limit={$limit}&offset={$offset}");
}

public function getPlaylistTracks(string $playlistId, int $limit = 100, int $offset = 0): string
{
    $this->setServiceTokens();

    $response = $this->service->getPlaylistTracks($playlistId, $limit, $offset);

    if (!is_array($response) || !isset($response['items'])) {
        Yii::warning("Invalid Spotify tracks response for playlist $playlistId: " . json_encode($response), __METHOD__);
        return json_encode([]);
    }

    // Only keep tracks you want (if needed)
    $tracks = [];
    foreach ($response['items'] as $item) {
        if (isset($item['track']) && $item['track']['type'] === 'track' && empty($item['track']['is_local'])) {
            $tracks[] = $item['track'];
        }
    }

    return json_encode($tracks, JSON_UNESCAPED_UNICODE);
}



    public function getAllPlaylistTracks(): array
    {
        $allTracks = [];
        $playlists = $this->getUserPlaylists();

        foreach ($playlists as $playlist) {
            $pid = $playlist['id'];
            $allTracks[$pid] = $this->getPlaylistTracks($pid);
        }

        return $allTracks;
    }
}
