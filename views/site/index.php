<?php
$this->title = 'Multi-Platform Playlist Tracker';
use yii\helpers\Html;
use yii\helpers\Url;
?>

<div class="site-index">
    <div class="jumbotron text-center bg-transparent mt-5 mb-5">
        <h1 class="display-4">Playlist Tracker</h1>
        <p class="lead">Track playlists across platforms and monitor changes over time.</p>
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
                                        <div class="track mb-2 p-2 border rounded d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= Html::encode($track->title) ?></strong> - <?= Html::encode($track->artist) ?>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-success play-track" 
                                                    data-platform="<?= $track->platform ?>" 
                                                    data-uri="<?= $track->platform_id ?>">
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

        <!-- Global Player -->
        <div class="mt-4">
            <h5>Now Playing:</h5>
            <div id="global-player" style="width:100%; min-height:100px;">
                <iframe id="player-iframe" src="" width="100%" height="180" frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen
                    style="display:none;"></iframe>
            </div>
        </div>

    </div>
</div>

<?php
$syncUrl = Url::to(['/site/sync-playlist']);
$js = <<<JS
$('.toggle-tracks').click(function(e){
    e.preventDefault();
    const pid = $(this).data('id');
    $('#tracks-' + pid).slideToggle();
});

$('.play-track').click(function(){
    const platform = $(this).data('platform');
    const uri = $(this).data('uri');
    const iframe = $('#player-iframe');

    if(platform === 'spotify'){
        iframe.attr('src', 'https://open.spotify.com/embed/track/' + uri).show();
        iframe.attr('height', '180');
    } else if(platform === 'youtube'){
        iframe.attr('src', 'https://www.youtube.com/embed/' + uri + '?autoplay=1').show();
        iframe.attr('height', '180');
    }

    // scroll to player
    $('html, body').animate({ scrollTop: iframe.offset().top - 100 }, 300);
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
