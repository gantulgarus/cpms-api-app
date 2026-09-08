<?php

namespace App\Http\Middleware;

use App\Models\Block;
use App\Models\WorkItem;
use App\Services\WorkScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Хэрэглэгчийн хамрах хүрээг СЕРВЕР дээр мөрдүүлнэ.
 *
 * Захиалагчийн RULE-09: "Users see only assigned project/block/work unless
 * management role." Үүнийг зөвхөн UI-д нуувал хамгаалалт болохгүй — хүн API
 * руу шууд хандаад өөр хүний өгөгдөл авч чадна.
 *
 * Хоёр түвшинд шалгана:
 *   1. БЛОК — талбайн инженерийн хамрах хүрээ.
 *   2. АЖЛЫН НЭГЖ — гүйцэтгэгч тухайн барилгад ажилладаг ч тэр барилгын
 *      БҮХ ажил түүнийх биш. Блокийн шалгалт үүнийг барихгүй.
 */
class EnsureWorkScope
{
    public function __construct(private readonly WorkScope $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $workItem = $this->resolveWorkItem($request);

        if ($workItem !== null) {
            abort_unless(
                $this->scope->allows($user, $workItem),
                403,
                'Энэ ажил таны хариуцлагад байхгүй.'
            );
        }

        if (! $user->canSeeAllBlocks()) {
            $blockId = $workItem?->block_id ?? $this->resolveBlockId($request);

            abort_if(
                $blockId !== null && ! $user->canSeeBlock($blockId),
                403,
                'Танд энэ блокийг харах эрх байхгүй.'
            );
        }

        return $next($request);
    }

    /**
     * Route model binding хараахан ажиллаагүй байж болох тул модель ба түүхий
     * id хоёуланг нь боловсруулна.
     */
    private function resolveWorkItem(Request $request): ?WorkItem
    {
        $workItem = $request->route('workItem');

        if ($workItem instanceof WorkItem) {
            return $workItem;
        }

        if (is_string($workItem)) {
            return WorkItem::find($workItem);
        }

        return null;
    }

    private function resolveBlockId(Request $request): ?string
    {
        $block = $request->route('block');

        if ($block instanceof Block) {
            return $block->id;
        }

        return is_string($block) ? $block : null;
    }
}
