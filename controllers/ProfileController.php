<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\models\ApiAccount;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\filters\Cors;
use app\models\LoginForm;
use app\models\ContactForm;
use app\models\Playlist;
use app\models\PlaylistTrack;
use app\models\Track;
use app\components\adapters\SpotifyAdapter;
use app\components\SpotifyService;
use app\components\YoutubeService;
use app\components\adapters\YoutubeAdapter;

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

    \ApiAccount::createOrUpdate(Yii::$app->user->id, 'spotify', [
        'access_token'  => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'] ?? null,
        'scope'         => $tokens['scope'] ?? null,
        'token_type'    => $tokens['token_type'] ?? null,
        'expires_at'    => date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600)),
        'raw'           => json_encode($tokens, JSON_THROW_ON_ERROR),
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

    public function actionRefreshPlaylists()
{
    $userId = Yii::$app->user->id;
    $spotify = ApiAccount::getAccount($userId, 'spotify');
    $youtube = ApiAccount::getAccount($userId, 'youtube');

    $addedTotal = 0;

    try {
        if ($spotify) {
            $service = Yii::$app->spotifyService;
            $adapter = new \app\components\adapters\SpotifyAdapter($service);
            $adapter->setTokens($spotify->access_token, $spotify->refresh_token ?? null);

            foreach ($adapter->getPlaylists() as $p) {
                if (empty($p['id'])) continue;

                $playlist = Playlist::findOne([
                    'platform_id' => $p['id'],
                    'api_account_id' => $spotify->id
                ]) ?? new Playlist();

                $playlist->api_account_id = $spotify->id;
                $playlist->platform = 'spotify';
                $playlist->platform_id = $p['id'];
                $playlist->name = $p['name'];
                $playlist->track_count = $p['track_count'];
                $playlist->save();

                $addedTotal += Yii::$app->controller->syncPlaylistTracks($playlist, $adapter);
            }
        }

        if ($youtube) {
            $service = Yii::$app->youtubeService;
            $service->setAccessToken($youtube->access_token, $youtube->refresh_token ?? null);
            $adapter = new \app\components\adapters\YoutubeAdapter($service);

            foreach ($adapter->getPlaylists() as $p) {
                if (empty($p['id'])) continue;

                $playlist = Playlist::findOne([
                    'platform_id' => $p['id'],
                    'api_account_id' => $youtube->id
                ]) ?? new Playlist();

                $playlist->api_account_id = $youtube->id;
                $playlist->platform = 'youtube';
                $playlist->platform_id = $p['id'];
                $playlist->name = $p['title'] ?: 'Untitled';
                $playlist->track_count = $p['itemCount'] ?? 0;
                $playlist->save();

                $addedTotal += Yii::$app->controller->syncYoutubePlaylistTracks($playlist, $adapter);
            }
        }

        Yii::$app->session->setFlash('success', "Playlists refreshed. $addedTotal new tracks added.");
    } catch (\Throwable $e) {
        Yii::error($e->getMessage(), __METHOD__);
        Yii::$app->session->setFlash('error', "Failed to refresh playlists: {$e->getMessage()}");
    }

    return $this->redirect(['index']);
}

private function syncPlaylistTracks(Playlist $playlist, SpotifyAdapter $adapter)
    {
        $offset = 0;
        $limit = 100;
        $addedCount = 0;

        Yii::info("Starting sync for playlist {$playlist->name} ({$playlist->platform_id})", __METHOD__);

        do {
            $tracks = $adapter->getPlaylistTracks($playlist->platform_id, $limit, $offset);

            Yii::info("Fetched " . count($tracks) . " tracks from offset $offset", __METHOD__);

            foreach ($tracks as $trackData) {
                if (!isset($trackData['id'])) {
                    Yii::info("Skipping track with no ID: " . json_encode($trackData), __METHOD__);
                    continue;
                }

                $track = Track::findOne([
                    'platform' => 'spotify',
                    'platform_id' => $trackData['id'],
                ]) ?? new Track();

                $track->platform = 'spotify';
                $track->platform_id = $trackData['id'];
                $track->title = $trackData['title'];
                $track->artist = $trackData['artist'];
                $track->album = $trackData['album'];
                $track->duration_ms = $trackData['duration_ms'];
                $track->preview_url = $trackData['preview_url'] ?? null;
                $track->raw = json_encode($trackData, JSON_UNESCAPED_UNICODE);

                if (!$track->save()) {
                    Yii::error("Failed to save track {$track->title}: " . json_encode($track->getErrors()), __METHOD__);
                    continue;
                }

                $pt = PlaylistTrack::findOne([
                    'playlist_id' => $playlist->id,
                    'track_id' => $track->id,
                ]) ?? new PlaylistTrack();

                if ($pt->isNewRecord) {
                    $pt->playlist_id = $playlist->id;
                    $pt->track_id = $track->id;
                    if (!$pt->save()) {
                        Yii::error("Failed to save PlaylistTrack for track {$track->title}: " . json_encode($pt->getErrors()), __METHOD__);
                        continue;
                    }

                    $addedCount++;
                    Yii::info("Added track: {$track->title} by {$track->artist}", __METHOD__); // <-- track-level log
                }
            }

            $offset += $limit;
        } while (!empty($tracks));

        $playlist->track_count = PlaylistTrack::find()->where(['playlist_id' => $playlist->id])->count();
        $playlist->last_synced_at = date('Y-m-d H:i:s');

        if (!$playlist->save()) {
            Yii::error("Failed to update playlist {$playlist->name}: " . json_encode($playlist->getErrors()), __METHOD__);
        } else {
            Yii::info("Playlist {$playlist->name} synced with $addedCount new tracks", __METHOD__);
        }

        return $addedCount;
    }
    private function syncYoutubePlaylistTracks(Playlist $playlist, YoutubeAdapter $adapter)
{
    $addedCount = 0;
    Yii::info("Starting sync for playlist '{$playlist->name}' ({$playlist->platform_id})", __METHOD__);

    $tracks = $adapter->getPlaylistTracks($playlist->platform_id);
    Yii::info("Adapter returned " . count($tracks) . " tracks", __METHOD__);

    foreach ($tracks as $i => $trackData) {
        Yii::info("Track $i raw data: " . json_encode($trackData), __METHOD__);

        $trackId = $trackData['id'] ?? null;
        if (!$trackId) {
            Yii::warning("Skipping track with missing ID", __METHOD__);
            continue;
        }

        $track = Track::findOne(['platform' => 'youtube', 'platform_id' => $trackId]) ?? new Track();
        $track->platform = 'youtube';
        $track->platform_id = $trackId;
        $track->title = $trackData['title'] ?? 'Untitled';
        $track->artist = $trackData['artist'] ?? 'Unknown';
        $track->duration_ms = $trackData['duration_ms'] ?? null;
        $track->preview_url = $trackData['preview_url'] ?? null;
        $track->raw = json_encode($trackData, JSON_UNESCAPED_UNICODE);

        if (!$track->save()) {
            Yii::error("Failed to save track '{$track->title}': " . json_encode($track->getErrors()), __METHOD__);
            continue;
        }

        $pt = PlaylistTrack::findOne(['playlist_id' => $playlist->id, 'track_id' => $track->id]) ?? new PlaylistTrack();
        if ($pt->isNewRecord) {
            $pt->playlist_id = $playlist->id;
            $pt->track_id = $track->id;
            if (!$pt->save()) {
                Yii::error("Failed to save PlaylistTrack for '{$track->title}': " . json_encode($pt->getErrors()), __METHOD__);
                continue;
            }
            $addedCount++;
        }
    }

    $playlist->track_count = PlaylistTrack::find()->where(['playlist_id' => $playlist->id])->count();
    $playlist->last_synced_at = date('Y-m-d H:i:s');

    if (!$playlist->save()) {
        Yii::error("Failed to update playlist '{$playlist->name}': " . json_encode($playlist->getErrors()), __METHOD__);
    } else {
        Yii::info("Finished syncing playlist '{$playlist->name}' with $addedCount new tracks", __METHOD__);
    }

    return $addedCount;
}


}
