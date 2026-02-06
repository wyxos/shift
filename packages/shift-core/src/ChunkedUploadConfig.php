<?php

namespace Shift\Core;

final class ChunkedUploadConfig
{
    public const MAX_UPLOAD_BYTES = 41943040; // 40MB
    public const MAX_UPLOAD_KB = 40960;
    public const CHUNK_SIZE_BYTES = 524288; // 512KB
    public const CHUNK_SIZE_KB = 512;
}
