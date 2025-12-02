<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\components\strategies\SpotifyStrategy;
use app\components\PlaylistContext;

class SpotifyController extends Controller
{
    /**
     * Step 1: Redirect user to Spotify login
     */
    public function actionLogin()
    {
        $strategy = new SpotifyStrategy();
        $context = new PlaylistContext($strategy);

        // Redirect user to Spotify OAuth page
        return $this->redirect($context->getAuthUrl());
    }

    /**
     * Step 2: Spotify redirects back with authorization code
     */
    public function actionCallback($code)
    {
        $userId = Yii::$app->user->id;

        if (!$userId) {
            throw new \Exception("You must be logged in to connect Spotify");
        }

        $strategy = new SpotifyStrategy();
        $context = new PlaylistContext($strategy);

        // Exchange code for access token
        $tokens = $context->exchangeCodeForToken($code);

        // Fetch Spotify user info
        $spotifyUser = $this->getSpotifyUserInfo($tokens['access_token']);
        $platformUserId = $spotifyUser['id'] ?? null;

        if (!$platformUserId) {
            throw new \Exception("Failed to retrieve Spotify user ID");
        }

        // ---------- MANUAL DB STORAGE ----------
        $platform = 'spotify';

        $existing = (new \yii\db\Query())
            ->from('api_account')
            ->where([
                'user_id' => $userId,
                'platform' => $platform,
                'platform_user_id' => $platformUserId
            ])
            ->one();

        $data = [
            'user_id' => $userId,
            'platform' => $platform,
            'platform_user_id' => $platformUserId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? ($existing['refresh_token'] ?? null),
            'scope' => $tokens['scope'],
            'token_type' => $tokens['token_type'],
            'expires_at' => date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600)),
            'raw' => json_encode($tokens),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Yii::$app->db->createCommand()
                ->update('api_account', $data, ['id' => $existing['id']])
                ->execute();
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            Yii::$app->db->createCommand()
                ->insert('api_account', $data)
                ->execute();
        }
        // ---------- END MANUAL DB STORAGE ----------

        // Fetch playlists
        $playlists = $context->fetchPlaylists($tokens['access_token']);

        // Render playlists
        return $this->render('playlists', ['playlists' => $playlists]);
    }

    /**
     * Helper: Get Spotify user info using access token
     */
    private function getSpotifyUserInfo(string $accessToken): array
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\n"
            ]
        ];
        $response = file_get_contents('https://api.spotify.com/v1/me', false, stream_context_create($opts));
        return json_decode($response, true);
    }
}
