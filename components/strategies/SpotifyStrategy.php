<?php
namespace app\components\strategies;

use app\components\SpotifyService;

class SpotifyStrategy implements PlaylistStrategy
{
    private SpotifyService $service;

    public function __construct()
    {
        // Pull credentials and redirect URI from params
        $this->service = new SpotifyService([
            'clientId'     => \Yii::$app->params['spotifyClientId'],
            'clientSecret' => \Yii::$app->params['spotifyClientSecret'],
            'redirectUri'  => \Yii::$app->params['spotifyRedirectUri'],
        ]);
    }

    /**
     * Step 1: Return the OAuth URL for Spotify login
     */
    public function getAuthUrl(): string
    {
        return $this->service->getAuthUrl();
    }

    /**
     * Step 2: Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->service->exchangeCodeForToken($code);
    }

    /**
     * Step 3: Fetch playlists for the authenticated user
     */
    public function fetchPlaylists(string $accessToken): array
    {
        $playlists = $this->service->getUserPlaylists($accessToken);

        // Normalize playlists
        $result = [];
        foreach ($playlists['items'] ?? [] as $p) {
            $result[] = [
                'id'           => $p['id'],
                'name'         => $p['name'],
                'tracks_total' => $p['tracks']['total'] ?? 0,
                'platform'     => 'spotify',
            ];
        }
        return $result;
    }
}
