<?php

declare(strict_types=1);

namespace App\GraphQL\Query;

use Overblog\GraphQLBundle\Definition\Resolver\QueryInterface;

final class PingQuery implements QueryInterface
{
    public function ping(): int
    {
        return 1;
    }
}
