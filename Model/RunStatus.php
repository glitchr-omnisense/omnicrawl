<?php

namespace Omnicrawl\Model;

/** Where a crawl run stands, whatever the service calls it. */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return self::Queued !== $this && self::Running !== $this;
    }
}
