<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Schema\ColumnDefinition;

class TidbColumnDefinition extends ColumnDefinition
{
    /**
     * Without bits the connection's auto_random config applies, then the server default of 5 shard bits.
     */
    public function autoRandom(?int $shardBits = null, ?int $rangeBits = null): static
    {
        return $this->set('autoRandom', $shardBits === null && $rangeBits === null ? true : [$shardBits, $rangeBits]);
    }
}
