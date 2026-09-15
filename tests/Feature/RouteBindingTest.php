<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Route-model binding-ийн НЭР таарч байгаа эсэх.
 *
 * ЯАГААД ЭНЭ ТЕСТ БАЙНА: Laravel нь моделийг ТӨРЛӨӨР нь биш НЭРЭЭР нь
 * холбодог. Route дээр `{blockDesign}` гэж бичээд метод дээр `$design` гэж
 * хүлээж авбал холболт ЧИМЭЭГҮЙ унтарна — 404 гарахгүй, харин контейнер
 * ХООСОН модель өгнө.
 *
 * Тэр хоосон модель нь `id = null` тул алдаа нь огт өөр газар, огт өөр
 * үгээр гарна. Бодит жишээ:
 *
 *     App\Jobs\ApplyBlockDesign::__construct():
 *     Argument #2 ($designId) must be of type string, null given
 *
 * Энэ мессежийг хараад route-ын параметрийн нэр буруу байна гэж хэн ч
 * бодохгүй. Тиймээс гараар олохыг хүлээхгүй, автоматаар барина.
 *
 * Энэ тест ямар нэг HTTP дуудлага хийхгүй — зөвхөн гарын үсгийг уншина.
 */
class RouteBindingTest extends TestCase
{
    public function test_every_model_argument_matches_a_route_parameter(): void
    {
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;   // closure эсвэл invokable
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            $parameters = $route->parameterNames();

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $argument) {
                $type = $argument->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                // Зөвхөн Eloquent модель холбогддог. `Request`, FormRequest,
                // сервисүүд нь контейнерээс ирдэг тул нэр нь хамаагүй.
                if (! is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }

                if (! in_array($argument->getName(), $parameters, true)) {
                    $problems[] = sprintf(
                        '%s → %s::%s() нь $%s гэж хүлээж байна, route нь {%s} гэж өгч байна.',
                        $route->uri(),
                        class_basename($class),
                        $method,
                        $argument->getName(),
                        implode('}, {', $parameters) ?: '—',
                    );
                }
            }
        }

        $this->assertSame([], $problems, "Route-model binding зөрчил:\n".implode("\n", $problems));
    }
}
