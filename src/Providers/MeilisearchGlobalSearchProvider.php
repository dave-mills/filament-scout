<?php

namespace Kainiklas\FilamentScout\Providers;

use Exception;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class MeilisearchGlobalSearchProvider implements GlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $builder = GlobalSearchResults::make();

        foreach (Filament::getResources() as $resource) {
            /** @var \Filament\Resources\Resource $resource */
            if (! $resource::canGloballySearch()) {
                continue;
            }

            if (! method_exists($resource::getModel(), 'search')) {
                throw new Exception('The model is not searchable. Please add the Laravel Scout trait Searchable to the model.');
            }

            $search = $resource::getModel()::search(
                $query,
                function ($meiliSearch, string $query, array $options) {
                    $options['attributesToHighlight'] = ['*'];
                    $options['highlightPreTag'] = '<strong>';
                    $options['highlightPostTag'] = '</strong>';

                    $options['limit'] = (int) config('kainiklas-filament-scout.scout_search_limit', 50);

                    return $meiliSearch->search($query, $options);
                }
            );

            $scoutKeyName = app($resource::getModel())->getScoutKeyName();
            $hits = collect($search->raw()['hits'])->keyBy($scoutKeyName);

            $resourceResults = $search
                ->get()
                ->map(function (Model $record) use ($resource, $hits): ?GlobalSearchResult {
                    $url = $resource::getGlobalSearchResultUrl($record);

                    if (blank($url)) {
                        return null;
                    }

                    // @phpstan-ignore-next-line
                    $hit = $hits[$record->getScoutKey()];

                    $titleAttribute = $resource::getRecordTitleAttribute();
                    $formattedTitle = new HtmlString($hit['_formatted'][$titleAttribute]);

                    // expects to get array of [attributeName => attributeTitle]
                    $details = $resource::getGlobalSearchResultDetails($record);
                    $formattedDetails = [];
                    foreach ($details as $attributeName => $attributeTitle) {
                        $formattedDetails += [
                            $attributeTitle => new HtmlString($hit['_formatted'][$attributeName]),
                        ];
                    }

                    return new GlobalSearchResult(
                        title: $formattedTitle,
                        url: $url,
                        details: $formattedDetails,
                        actions: $resource::getGlobalSearchResultActions($record),
                    );
                })
                ->filter();

            if (! $resourceResults->count()) {
                continue;
            }

            $builder->category($resource::getPluralModelLabel(), $resourceResults);
        }

        return $builder;
    }
}
