<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Schema\IndexDefinition;

class TidbIndexDefinition extends IndexDefinition
{
    public function comment(string $comment): static
    {
        return $this->set('comment', $comment);
    }

    public function invisible(bool $value = true): static
    {
        return $this->set('invisible', $value);
    }

    /**
     * A global index on a partitioned table, unique across all partitions.
     */
    public function global(bool $value = true): static
    {
        return $this->set('global', $value);
    }

    /**
     * Only for primary keys; false asks for NONCLUSTERED.
     */
    public function clustered(bool $value = true): static
    {
        return $this->set('clustered', $value);
    }
}
