<?php
// @expect: EXEC
// @targets: command:ffmpeg
function convert(string $file): void
{
    proc_open(['ffmpeg', '-i', $file, 'out.mp4'], [], $pipes);
}
