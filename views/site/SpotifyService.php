<?php
namespace app\components;

use Yii;
use yii\base\Component;
use app\models\ApiAccount;

class SpotifyService extends Component
{
    public $clientId;
    public $clientSecret;
    public $redirectUri;

    /** Step 1: Generate authorization URL */
    public function getAuthUrl()
    {
        $scope = urlencode('playlist-read-private playlist-read-collaborative user-read-email');
        return "https://accounts.spotify.com/authorize?client_id={$this->clientId}" .
               "&response_type=code&redirect_uri={$this->redirectUri}" .
               "&scope={$scope}";
    }

    /** Step 2: Exchange code for token */
    public function exchangeCodeForToken($code)
    {
        $post = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Basic " . base64_encode("{$this->clientId}:{$this->clientSecret}") .
                            "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post
            ]
        ];

        $response = file_get_contents('https://accounts.spotify.com/api/token', false, stream_context_create($opts));
        return json_decode($response, true);
    }
}
