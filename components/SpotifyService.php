<?php
namespace app\components;

use Yii;
use yii\base\Component;

class SpotifyService extends Component
{
    public string $clientId;
    public string $clientSecret;
    public string $redirectUri;

    // Standard Spotify Base URLs
    const AUTH_URL  = 'https://accounts.spotify.com/authorize';
    const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    const API_BASE  = 'https://api.spotify.com/v1';

    /**
     * Step 1: Return the OAuth URL to redirect the user to Spotify login
     */
    public function getAuthUrl(): string
    {
        $scope = urlencode('playlist-read-private playlist-read-collaborative user-read-email');
        
        // FIX: Use correct Spotify Authorize URL
        return self::AUTH_URL 
            . "?client_id={$this->clientId}"
            . "&response_type=code"
            . "&redirect_uri=" . urlencode($this->redirectUri)
            . "&scope={$scope}";
    }

    /**
     * Step 2: Exchange authorization code for access token
     */
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
                'ignore_errors' => true // Allows reading the body even on 4xx errors
            ]
        ];

        // FIX: Use correct Token URL
        $response = file_get_contents(self::TOKEN_URL, false, stream_context_create($opts));
        return json_decode($response, true);
    }

    /**
     * Step 3: Fetch user's playlists
     */
    public function getUserPlaylists(string $accessToken, int $limit = 50, int $offset = 0): array
    {
        // FIX: Use correct API URL
        $url = self::API_BASE . "/me/playlists?limit={$limit}&offset={$offset}";

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'ignore_errors' => true
            ]
        ];

        $response = file_get_contents($url, false, stream_context_create($opts));
        return json_decode($response, true);
    }

    /* Optional: Refresh the access token using refresh_token */
    public function refreshAccessToken(string $refreshToken): array
    {
        $post = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken
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
        return json_decode($response, true);
    }
}