<?php

namespace Folklore\Eloquent;

enum ReferenceOperation: string
{
    case Read = 'read';
    case Write = 'write';
}
