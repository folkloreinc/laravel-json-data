<?php

namespace Folklore\JsonData;

enum ReferenceOperation: string
{
    case Read = 'read';
    case Write = 'write';
}
