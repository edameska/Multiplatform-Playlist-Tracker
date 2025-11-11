<?php
use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

$this->title = 'Signup';
?>

<h1><?= Html::encode($this->title) ?></h1>

<?php $form = ActiveForm::begin(); ?>

<?= $form->field($model, 'username')->textInput() ?>
<?= $form->field($model, 'password')->passwordInput() ?>
<?= $form->field($model, 'password_repeat')->passwordInput() ?>

<div class="form-group">
    <?= Html::submitButton('Signup', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>
