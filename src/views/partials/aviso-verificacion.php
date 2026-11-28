<?php

declare(strict_types=1);

use App\View\Html;
use App\View\Ui;

/**
 * Recordatorio de verificar el correo (A1), en las páginas del usuario mientras el correo siga sin verificar.
 *
 * @var bool $esPerfil true si ya estás en Mi perfil (el botón de reenvío está ahí mismo).
 */
?>
<?= Ui::notice(new Html('<strong>Confirma tu correo.</strong> Te enviamos un enlace para verificarlo. Hasta que lo hagas no podrás enviar ni aceptar invitaciones a espacios compartidos ni recibir avisos por correo. '
    . ($esPerfil ? 'Puedes pedir otro enlace más abajo.' : '<a href="/perfil.php#correo">Reenviar el enlace desde Mi perfil</a>.')), 'warn') ?>
