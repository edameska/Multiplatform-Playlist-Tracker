<?php
$this->title = 'Multi-Platform Playlist Tracker';
use yii\helpers\Html;
use yii\helpers\Url;
?>

<div class="site-index">
    <div class="jumbotron text-center bg-transparent mt-5 mb-5">
        <h1 class="display-4">Playlist Tracker</h1>
        <p class="lead">Track playlists across platforms and monitor changes over time.</p>
        <p><a class="btn btn-lg btn-success" href="/playlist/add">+ Add a Playlist</a></p>
    </div>

    <div class="body-content">

        <h3>Your Playlists</h3>

        <div class="row">
            <?php if (empty($playlists)): ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        No playlists found yet. Connect a platform or add your first playlist 🎵
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($playlists as $playlist): ?>
                    <div class="col-lg-4 mb-3">
                        <div class="card p-3 shadow-sm h-100">
                            <h4><?= Html::encode($playlist->name) ?></h4>

                            <p class="mb-2">
                                <strong>Platform:</strong> <?= ucfirst($playlist->platform) ?><br>
                                <strong>Songs:</strong> <?= (int)$playlist->track_count ?><br>
                                <strong>Last sync:</strong>
                                <?= $playlist->last_synced_at
                                    ? Yii::$app->formatter->asRelativeTime($playlist->last_synced_at)
                                    : 'Never' ?>
                            </p>

                            <div class="mb-2">
                                <a href="#" class="btn btn-outline-secondary btn-sm sync-playlist" data-id="<?= $playlist->id ?>">
                                    Sync Now
                                </a>
                                <a href="#" class="btn btn-outline-primary btn-sm toggle-tracks" data-id="<?= $playlist->id ?>">
                                    View Tracks
                                </a>
                            </div>

                            <div class="tracks-container mt-2" id="tracks-<?= $playlist->id ?>" style="display:none;">
                                <?php if (!empty($playlist->tracks)): ?>
                                    <?php foreach ($playlist->tracks as $track): ?>
                                        <div class="track mb-2 p-2 border rounded">
                                            <strong><?= Html::encode($track->title) ?></strong> - <?= Html::encode($track->artist) ?>
                                            <div class="mt-1">
                                                <button class="btn btn-sm btn-outline-success play-track" data-uri="<?= $track->platform_id ?>">
                                                    Play
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="alert alert-light">
                                        No tracks found in this playlist.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h3 class="mt-5">Recent Activity</h3>
        <ul>
            <li>Playlist <em>Top Hits</em> added 2 new songs.</li>
            <li>Playlist <em>Chill Vibes</em> synced 3 hours ago.</li>
        </ul>

        <div class="spotify-player mt-4">
            <iframe id="spotify-player" src="" width="100%" height="80" frameborder="0" allowtransparency="true" allow="encrypted-media" style="display:none;"></iframe>
        </div>
    </div>
</div>

<?php
$syncUrl = Url::to(['/site/sync-playlist']); // implement actionSiteController->actionSyncPlaylist($id)
$js = <<<JS
$('.toggle-tracks').click(function(e){
    e.preventDefault();
    const pid = $(this).data('id');
    $('#tracks-' + pid).slideToggle();
});

$('.play-track').click(function(){
    const uri = $(this).data('uri');
    $('#spotify-player').attr('src', 'https://open.spotify.com/embed/track/' + uri).show();
});

$('.sync-playlist').click(function(e){
    e.preventDefault();
    const pid = $(this).data('id');
    $.post('{$syncUrl}', {id: pid}, function(res){
        alert('Playlist synced!');
        location.reload();
    }).fail(function(){
        alert('Sync failed!');
    });
});
JS;
$this->registerJs($js);
?>
