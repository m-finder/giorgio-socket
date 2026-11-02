<?php

namespace GiorgioSocket\Services\Native;

class Frame
{
    public function __construct(
        public int $fd,
        public string $data,
        public int $opcode = 1,
        public bool $finish = true
    ) {}
}
