<?php
namespace app\components\strategies;

use app\components\SpotifyService;
use app\models\ApiAccount;
use app\models\Playlist;
use app\models\Track;
use app\models\PlaylistTrack;
use Yii;

class SpotifyStrategy implements PlaylistStrategy
{
    private SpotifyService $service;

    public function __construct()
    {
        $this->service = new SpotifyService([
            'clientId'     => Yii::$app->params['spotifyClientId'],
            'clientSecret' => Yii::$app->params['spotifyClientSecret'],
            'redirectUri'  => Yii::$app->params['spotifyRedirectUri'],
        ]);
    }

    public function getAuthUrl(): string
    {
        return $this->service->getAuthUrl();
    }

    public function exchangeCodeForToken(string $code): array
    {
        $userId = Yii::$app->user->id;
        $tokens = $this->service->exchangeCodeForToken($code);

        ApiAccount::createOrUpdate($userId, 'spotify', [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'scope'         => $tokens['scope'] ?? null,
            'token_type'    => $tokens['token_type'] ?? null,
            'expires_at'    => date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600)),
            'raw'           => json_encode($tokens, JSON_THROW_ON_ERROR),
        ]);

        $this->service->setAccessToken($tokens['access_token'], $tokens['refresh_token'] ?? null);

        return $tokens;
    }

    private function setServiceTokens(): void
    {
        $account = ApiAccount::findOne([
            'user_id'  => Yii::$app->user->id,
            'platform' => 'spotify'
        ]);

        if (!$account) {
            throw new \Exception("No Spotify account found for user");
        }

        $this->service->setAccessToken($account->access_token, $account->refresh_token ?? null);
    }

    public function fetchMe(): array
    {
        $this->setServiceTokens();
        $user = $this->service->apiGet('/me');
        if (!is_array($user) || empty($user['id'])) {
            Yii::warning("Failed to fetch Spotify /me for user " . Yii::$app->user->id);
            return [];
        }
        return $user;
    }

    public function fetchPlaylists(): string
{
    $this->setServiceTokens();
    $playlistsJson = $this->service->getUserPlaylists();
    Yii::info("Fetched playlists JSON: " . substr($playlistsJson, 0, 1000));
    return $playlistsJson; // JSON string
}

public function getPlaylistTracks(string $playlistId, int $limit = 100, int $offset = 0): string
{
    $this->setServiceTokens(); // <-- strategy method
    $tracks = $this->service->getPlaylistTracks($playlistId, $limit, $offset);

    // return JSON string
    return json_encode($tracks, JSON_UNESCAPED_UNICODE);
}



}
