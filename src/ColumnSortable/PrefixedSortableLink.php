<?php declare(strict_types=1);

namespace Kyslik\ColumnSortable;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Kyslik\ColumnSortable\Exceptions\ColumnSortableException;

/**
 * Class PrefixedSortableLink
 */
class PrefixedSortableLink
{
    /**
     * @param array $parameters
     *
     * @return string
     * @throws \Kyslik\ColumnSortable\Exceptions\ColumnSortableException
     */
    public static function render(array $parameters)
    {
        [$sortColumn, $sortParameter, $title, $queryPrefix, $queryParameters, $anchorAttributes] = self::parseParameters($parameters);

        $title = self::applyFormatting($title, $sortColumn);

        if ($mergeTitleAs = config('columnsortable.inject_title_as', null)) {
            request()->merge([$mergeTitleAs => $title]);
        }

        [$icon, $direction] = self::determineDirection($sortColumn, $sortParameter, $queryPrefix);

        $trailingTag = self::formTrailingTag($icon);

        $anchorClass = self::getAnchorClass($sortParameter, $anchorAttributes);

        $anchorAttributesString = self::buildAnchorAttributesString($anchorAttributes);

        $queryString = self::buildQueryString($queryParameters, $sortParameter, $direction, $queryPrefix);

        $url = self::buildUrl($queryString, $anchorAttributes);

        return '<a' . $anchorClass . ' href="' . $url . '"' . $anchorAttributesString . '>' . e($title) . $trailingTag;
    }

    /**
     * @param array $parameters
     *
     * @return array
     * @throws \Kyslik\ColumnSortable\Exceptions\ColumnSortableException
     */
    public static function parseParameters(array $parameters)
    {
        //TODO: let 2nd parameter be both title, or default query parameters
        //TODO: needs some checks before determining $title
        $explodeResult = self::explodeSortParameter($parameters[0]);
        $sortColumn = (empty($explodeResult)) ? $parameters[0] : $explodeResult[1];
        $title = (count($parameters) === 1) ? null : $parameters[1];
        $queryPrefix = (isset($parameters[2]) && is_string($parameters[2])) ? $parameters[2] : '';
        $queryParameters = (isset($parameters[3]) && is_array($parameters[2])) ? $parameters[3] : [];
        $anchorAttributes = (isset($parameters[4]) && is_array($parameters[3])) ? $parameters[4] : [];

        return [$sortColumn, $parameters[0], $title, $queryPrefix, $queryParameters, $anchorAttributes];
    }

    /**
     * Explodes parameter if possible and returns array [column, relation]
     * Empty array is returned if explode could not run eg: separator was not found.
     *
     * @param $parameter
     *
     * @return array
     *
     * @throws \Kyslik\ColumnSortable\Exceptions\ColumnSortableException
     */
    public static function explodeSortParameter($parameter)
    {
        $separator = config('columnsortable.uri_relation_column_separator', '.');

        if (Str::contains($parameter, $separator)) {
            $oneToOneSort = explode($separator, $parameter);

            if (count($oneToOneSort) !== 2) {
                throw new ColumnSortableException();
            }

            return $oneToOneSort;
        }

        return [];
    }

    /**
     * @param string|\Illuminate\Contracts\Support\Htmlable|null $title
     * @param string $sortColumn
     *
     * @return string
     */
    private static function applyFormatting($title, $sortColumn)
    {
        if ($title instanceof Htmlable) {
            return $title;
        }

        if ($title === null) {
            $title = $sortColumn;
        } elseif (!config('columnsortable.format_custom_titles', true)) {
            return $title;
        }

        $formatting_function = config('columnsortable.formatting_function', null);

        if (!is_null($formatting_function) && function_exists($formatting_function)) {
            $title = call_user_func($formatting_function, $title);
        }

        return $title;
    }

    /**
     * @param $sortColumn
     * @param $sortParameter
     * @param mixed $queryPrefix
     *
     * @return array
     */
    private static function determineDirection($sortColumn, $sortParameter, $queryPrefix)
    {
        $icon = self::selectIcon($sortColumn);

        if (request()->get(self::sortQueryParameterName($queryPrefix)) == $sortParameter && in_array(request()->get(self::directionQueryParameterName($queryPrefix)), ['asc', 'desc'])) {
            $icon .= (request()->get(self::directionQueryParameterName($queryPrefix)) === 'asc' ? config('columnsortable.asc_suffix', '-asc') :
                config('columnsortable.desc_suffix', '-desc'));
            $direction = request()->get(self::directionQueryParameterName($queryPrefix)) === 'desc' ? 'asc' : 'desc';

            return [$icon, $direction];
        }
        $icon = config('columnsortable.sortable_icon');
        $direction = config('columnsortable.default_direction_unsorted', 'asc');

        return [$icon, $direction];
    }

    /**
     * @param $sortColumn
     *
     * @return string
     */
    private static function selectIcon($sortColumn)
    {
        $icon = config('columnsortable.default_icon_set');

        foreach (config('columnsortable.columns', []) as $value) {
            if (in_array($sortColumn, $value['rows'])) {
                $icon = $value['class'];
            }
        }

        return $icon;
    }

    /**
     * @param $icon
     *
     * @return string
     */
    private static function formTrailingTag($icon)
    {
        if (!config('columnsortable.enable_icons', true)) {
            return '</a>';
        }

        $iconAndTextSeparator = config('columnsortable.icon_text_separator', '');

        $clickableIcon = config('columnsortable.clickable_icon', false);
        $trailingTag = $iconAndTextSeparator . '<i class="' . $icon . '"></i>' . '</a>';

        if ($clickableIcon === false) {
            $trailingTag = '</a>' . $iconAndTextSeparator . '<i class="' . $icon . '"></i>';

            return $trailingTag;
        }

        return $trailingTag;
    }

    /**
     * Take care of special case, when `class` is passed to the sortablelink.
     *
     * @param $sortColumn
     *
     * @param array $anchorAttributes
     * @param mixed $queryPrefix
     *
     * @return string
     */
    private static function getAnchorClass($sortColumn, $queryPrefix, &$anchorAttributes = [])
    {
        $class = [];

        $anchorClass = config('columnsortable.anchor_class', null);

        if ($anchorClass !== null) {
            $class[] = $anchorClass;
        }

        $activeClass = config('columnsortable.active_anchor_class', null);

        if ($activeClass !== null && self::shouldShowActive($sortColumn, $queryPrefix)) {
            $class[] = $activeClass;
        }

        $directionClassPrefix = config('columnsortable.direction_anchor_class_prefix', null);

        if ($directionClassPrefix !== null && self::shouldShowActive($sortColumn, $queryPrefix)) {
            $class[] = $directionClassPrefix . (request()->get(self::directionQueryParameterName($queryPrefix)) === 'asc' ? config('columnsortable.asc_suffix', '-asc') :
                    config('columnsortable.desc_suffix', '-desc'));
        }

        if (isset($anchorAttributes['class'])) {
            $class = array_merge($class, explode(' ', $anchorAttributes['class']));
            unset($anchorAttributes['class']);
        }

        return (empty($class)) ? '' : ' class="' . implode(' ', $class) . '"';
    }

    /**
     * @param $sortColumn
     * @param mixed $queryPrefix
     *
     * @return bool
     */
    private static function shouldShowActive($sortColumn, $queryPrefix)
    {
        return request()->has(self::sortQueryParameterName($queryPrefix)) && request()->get(self::sortQueryParameterName($queryPrefix)) == $sortColumn;
    }

    /**
     * @param $queryParameters
     * @param $sortParameter
     * @param $direction
     * @param mixed $queryPrefix
     *
     * @return string
     */
    private static function buildQueryString($queryParameters, $sortParameter, $direction, $queryPrefix)
    {
        $checkStrlenOrArray = function ($element) {
            return is_array($element) ? $element : strlen($element);
        };

        $persistParameters = array_filter(request()->except(self::sortQueryParameterName($queryPrefix), self::directionQueryParameterName($queryPrefix), 'page'), $checkStrlenOrArray);
        $queryString = http_build_query(array_merge($queryParameters, $persistParameters, [
            self::sortQueryParameterName($queryPrefix) => $sortParameter,
            self::directionQueryParameterName($queryPrefix) => $direction,
        ]));

        return $queryString;
    }

    private static function buildAnchorAttributesString($anchorAttributes)
    {
        if (empty($anchorAttributes)) {
            return '';
        }

        unset($anchorAttributes['href']);

        $attributes = [];

        foreach ($anchorAttributes as $k => $v) {
            $attributes[] = $k . ($v != '' ? '="' . $v . '"' : '');
        }

        return ' ' . implode(' ', $attributes);
    }

    private static function buildUrl($queryString, $anchorAttributes)
    {
        if(!isset($anchorAttributes['href'])) {
            return url(request()->path() . '?' . $queryString);
        }

        return url($anchorAttributes['href'] . '?' . $queryString);
    }

    private static function sortQueryParameterName($prefix): string
    {
        return $prefix . 'sort';
    }

    private static function directionQueryParameterName($prefix): string
    {
        return $prefix . 'direction';
    }
}
