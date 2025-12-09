<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\models\ApiAccount;

class ProfileController extends Controller
{
    public function actionIndex()
{
    $userId = Yii::$app->user->id;
    $spotify = \app\models\ApiAccount::getAccount($userId, 'spotify');
    $youtube = \app\models\ApiAccount::getAccount($userId, 'youtube');

    // render myprofile.php instead of index.php
    return $this->render('myprofile', [
        'spotify' => $spotify,
        'youtube' => $youtube,
    ]);
}


    public function actionSpotifyConnect()
    {
        $spotify = Yii::$app->spotifyService;
        return $this->redirect($spotify->getAuthUrl());
    }


    public function actionSpotifyCallback($code)
{
    $spotify = Yii::$app->spotifyService;
    $tokens = $spotify->exchangeCodeForToken($code);

    if (!isset($tokens['access_token'])) {
        Yii::$app->session->setFlash('error', 'Failed to get Spotify token.');
        return $this->redirect(['index']);
    }

    \app\models\ApiAccount::createOrUpdate(Yii::$app->user->id, 'spotify', [
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'] ?? null,
        'scope' => $tokens['scope'] ?? null,
        'token_type' => $tokens['token_type'] ?? null,
        'expires_at' => date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600)),
        'raw' => $tokens,
    ]);

    return $this->redirect(['index']);
}

    public function actionYoutubeConnect()
    {
        $client = Yii::$app->youtubeService->getClient();
        return $this->redirect($client->createAuthUrl());
    }

    public function actionYoutubeCallback()
    {
        $client = Yii::$app->youtubeService->getClient();
        $code = Yii::$app->request->get('code');

        $client->authenticate($code);
        $tokens = $client->getAccessToken();

        ApiAccount::createOrUpdate(Yii::$app->user->id, 'youtube', [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'scope' => implode(" ", $client->getScopes()),
            'token_type' => 'Bearer',
            'expires_at' => date('c', time() + $tokens['expires_in']),
            'raw' => json_encode($tokens),
        ]);

        return $this->redirect(['index']);
    }
}
