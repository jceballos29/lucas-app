<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Categoria;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Validation\CategoriaValidator;
use App\View\View;
use RuntimeException;

/**
 * Categorías. `usuario()` (categorias.php): las predeterminadas, de solo lectura, y las propias del usuario, que puede
 * crear, editar y eliminar (RF-08, RF-09). `admin()` (admin/categorias.php): solo las predeterminadas, que gestiona el
 * administrador (RF-34); nunca ve las categorías propias de nadie (RNF-21).
 */
final class CategoriaController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function usuario(): void
    {
        $user = require_login();
        $this->handle($user, false);
    }

    public function admin(): void
    {
        $user = require_role('admin');
        $this->handle($user, true);
    }

    /** @param array{id: int, name: string, email: string, role: string} $user */
    private function handle(array $user, bool $isAdmin): void
    {
        $base = $isAdmin ? '/admin/categorias.php' : '/categorias.php';
        $owner = $isAdmin ? null : $user['id'];
        $errors = [];
        $old = ['nombre' => '', 'tipo' => 'gasto', 'color' => CategoriaValidator::COLOR_POR_DEFECTO];
        $editing = null;

        try {
            $repo = $this->container->categorias();

            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                reject_foreign_origin();
                require_csrf();

                $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
                $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;

                switch ($accion) {
                    case 'crear':
                        $result = (new CategoriaValidator())->validate($_POST, true);
                        $errors = $result['errors'];
                        $old = $result['data'];

                        if (!isset($errors['nombre']) && !isset($errors['tipo']) && $repo->nameTaken($owner, $old['nombre'], $old['tipo'])) {
                            $errors['nombre'] = 'Ya existe una categoría con ese nombre en ' . Categoria::TIPOS[$old['tipo']] . '.';
                        }

                        if ($errors === []) {
                            $repo->create($owner, $old['nombre'], $old['tipo'], $old['color']);
                            Flash::set('ok', "Se creó la categoría «{$old['nombre']}».");
                            Response::redirect($base);
                        }
                        break;

                    case 'guardar':
                        $editing = $this->target($repo, $user['id'], $isAdmin, $id);
                        $result = (new CategoriaValidator())->validate($_POST, false);
                        $errors = $result['errors'];
                        $old = ['nombre' => $result['data']['nombre'], 'tipo' => $editing->tipo, 'color' => $result['data']['color']];

                        if (!isset($errors['nombre']) && $repo->nameTaken($owner, $old['nombre'], $editing->tipo, $editing->id)) {
                            $errors['nombre'] = 'Ya existe una categoría con ese nombre en ' . Categoria::TIPOS[$editing->tipo] . '.';
                        }

                        if ($errors === []) {
                            $repo->update($owner, $editing->id, $old['nombre'], $old['color']);
                            Flash::set('ok', "Se guardaron los cambios de «{$old['nombre']}».");
                            Response::redirect($base);
                        }
                        break;

                    case 'eliminar':
                        $categoria = $this->target($repo, $user['id'], $isAdmin, $id);
                        [$tone, $message] = match ($repo->delete($owner, $categoria->id)) {
                            'ok' => ['ok', "Se eliminó la categoría «{$categoria->nombre}»."],
                            'en_uso' => ['error', "No se puede eliminar «{$categoria->nombre}» porque tiene movimientos o presupuestos. Puedes cambiarle el nombre en su lugar."],
                            default => ['error', 'Esa categoría ya no existe.'],
                        };
                        Flash::set($tone, $message);
                        Response::redirect($base);

                    default:
                        Response::plain(400, "Solicitud no válida.\n");
                }
            } elseif (isset($_GET['editar'])) {
                $raw = $_GET['editar'];
                $editing = $this->target($repo, $user['id'], $isAdmin, is_string($raw) && ctype_digit($raw) ? (int) $raw : 0);
                $old = ['nombre' => $editing->nombre, 'tipo' => $editing->tipo, 'color' => $editing->color];
            }

            $items = $isAdmin ? $repo->listDefaults() : $repo->listVisible($user['id']);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        View::page($isAdmin ? 'admin/categorias' : 'app/categorias', [
            'base' => $base,
            'isAdmin' => $isAdmin,
            'items' => $items,
            'editing' => $editing,
            'old' => $old,
            'errors' => $errors,
            'flash' => Flash::pull(),
        ], 'Categorías', $user, $isAdmin ? 'admin' : 'app', 'categorias');
    }

    /**
     * La categoría sobre la que se puede actuar, o corta con un error: 404 si no existe o es de otra persona (no se
     * revela que existe, RNF-04); 403 si un usuario intenta tocar una predeterminada.
     */
    private function target(\App\Repository\CategoriaRepository $repo, int $userId, bool $isAdmin, int $id): Categoria
    {
        $categoria = $id > 0 ? $repo->findById($id) : null;

        if ($categoria === null) {
            Response::plain(404, "No encontrado.\n");
        }

        if ($isAdmin) {
            return $categoria->esPredeterminada() ? $categoria : Response::plain(404, "No encontrado.\n");
        }

        if ($categoria->esPredeterminada()) {
            Response::plain(403, "Las categorías predeterminadas solo las gestiona un administrador.\n");
        }

        return $categoria->idUsuario === $userId ? $categoria : Response::plain(404, "No encontrado.\n");
    }
}
