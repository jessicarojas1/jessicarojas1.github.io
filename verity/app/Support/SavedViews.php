<?php

declare(strict_types=1);

namespace Verity\Support;

/** Saved Matrix views — personal and organization-shared (module 8). */
final class SavedViews
{
    public static function forUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT * FROM saved_view WHERE owner_user_id = :uid OR is_shared = TRUE ORDER BY is_shared, name',
            ['uid' => $userId]
        );
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM saved_view WHERE id = :id', ['id' => $id]);
    }

    public static function create(int $ownerId, string $name, string $scope, array $config, bool $shared, ?int $actorId): int
    {
        $id = Db::insert('saved_view', [
            'owner_user_id' => $ownerId,
            'name' => $name,
            'view_scope' => $scope,
            'is_shared' => $shared,
            'config' => $config,
        ]);
        Audit::log('savedview.create', 'saved_view#' . $id, null, ['name' => $name, 'scope' => $scope], null, $actorId);
        return $id;
    }

    public static function delete(int $id, ?int $actorId): void
    {
        $before = self::get($id);
        Db::delete('saved_view', ['id' => $id]);
        Audit::log('savedview.delete', 'saved_view#' . $id, $before, null, null, $actorId);
    }
}
