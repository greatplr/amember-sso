<?php

namespace Greatplr\AmemberSso\Support;

/**
 * Copies aMember user fields listed in `amember-sso.access_control.syncable_fields`
 * onto a local user model.
 *
 * aMember's name_f / name_l are not user columns: they are combined into
 * `name`. Other fields are copied as is, so they must exist on the users table.
 */
class UserDataSync
{
    protected const NAME_FIELDS = ['name_f', 'name_l'];

    /**
     * Apply the fields to $user without saving it. Returns whether anything changed.
     */
    public static function apply(object $user, array $amemberUser): bool
    {
        $syncableFields = config('amember-sso.access_control.syncable_fields', []);
        $changed = false;

        foreach (array_diff($syncableFields, self::NAME_FIELDS) as $field) {
            if (isset($amemberUser[$field]) && $user->{$field} !== $amemberUser[$field]) {
                $user->{$field} = $amemberUser[$field];
                $changed = true;
            }
        }

        if (array_intersect(self::NAME_FIELDS, $syncableFields)) {
            $fullName = trim(($amemberUser['name_f'] ?? '') . ' ' . ($amemberUser['name_l'] ?? ''));

            if ($fullName !== '' && $user->name !== $fullName) {
                $user->name = $fullName;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Display name for a new user: `name` if aMember sent one, else
     * "name_f name_l", else null.
     */
    public static function fullName(array $amemberUser): ?string
    {
        $name = trim($amemberUser['name'] ?? '');

        if ($name === '') {
            $name = trim(($amemberUser['name_f'] ?? '') . ' ' . ($amemberUser['name_l'] ?? ''));
        }

        return $name !== '' ? $name : null;
    }
}
