<?php

namespace Ges\LaravelGreenApi\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CastsOwnerKeyToStringBelongsTo extends BelongsTo
{
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($parentQuery->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return $query->select($columns)->whereRaw(sprintf(
            '%s = %s',
            $this->wrap($query, $this->getQualifiedForeignKeyName()),
            $this->castToString($query, $this->wrap($query, $query->qualifyColumn($this->ownerKey)))
        ));
    }

    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->select($columns)->from(
            $query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash()
        );

        $query->getModel()->setTable($hash);

        return $query->whereRaw(sprintf(
            '%s = %s',
            $this->wrap($query, $this->getQualifiedForeignKeyName()),
            $this->castToString($query, $this->wrap($query, $hash.'.'.$this->ownerKey))
        ));
    }

    private function castToString(Builder $query, string $column): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'cast('.$column.' as char)',
            'sqlsrv' => 'cast('.$column.' as nvarchar(max))',
            default => 'cast('.$column.' as text)',
        };
    }

    private function wrap(Builder $query, string $column): string
    {
        return $query->getQuery()->grammar->wrap($column);
    }
}
