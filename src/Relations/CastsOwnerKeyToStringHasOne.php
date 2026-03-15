<?php

namespace Ges\LaravelGreenApi\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CastsOwnerKeyToStringHasOne extends HasOne
{
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($query->getQuery()->from == $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return $query->select($columns)->whereRaw(sprintf(
            '%s = %s',
            $this->wrap($query, $this->getQualifiedForeignKeyName()),
            $this->castToString($query, $this->wrap($query, $this->getQualifiedParentKeyName()))
        ));
    }

    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereRaw(sprintf(
            '%s = %s',
            $this->wrap($query, $hash.'.'.$this->getForeignKeyName()),
            $this->castToString($query, $this->wrap($query, $this->getQualifiedParentKeyName()))
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
