<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\MoreI18N;

/** Find possible duplicate individuals in the complete current tree. */
final class SimilarPersonFinder
{
    /**
     * @return list<array{individual:Individual,name:string,birth:string,death:string,sex:string,score:int,evidence:list<string>,external_keys:list<string>}>
     */
    public function find(Tree $tree, Individual $source, int $limit = 20): array
    {
        $sourceNames = $this->names($source);
        $tokens = [];
        foreach ($sourceNames as $name) {
            foreach (preg_split('/\s+/u', $name) ?: [] as $token) {
                if (mb_strlen($token) >= 3) {
                    $tokens[$token] = true;
                }
            }
        }

        if ($tokens === []) {
            return [];
        }

        $query = DB::table('individuals')
            ->join('name', static function ($join): void {
                $join
                    ->on('name.n_file', '=', 'individuals.i_file')
                    ->on('name.n_id', '=', 'individuals.i_id');
            })
            ->where('individuals.i_file', '=', $tree->id())
            ->select(['individuals.i_file', 'individuals.i_id', 'individuals.i_gedcom'])
            ->distinct();

        $searchTokens = array_keys($tokens);
        $query->where(static function ($query) use ($searchTokens): void {
            foreach ($searchTokens as $token) {
                $escaped = addcslashes($token, "\\%_");
                $query->orWhere('name.n_full', DB::iLike(), '%' . $escaped . '%');
            }
        });

        $sourceKeys = $this->externalKeys($source);
        $sourceBirth = $this->dateValue($source, 'BIRT');
        $sourceDeath = $this->dateValue($source, 'DEAT');
        $sourceSex = $source->sex();
        $matches = [];

        // The query is intentionally scoped to the complete current tree.  Do
        // not cap the database candidates before scoring: a hard raw-row cap
        // could hide the best match in a large tree.  The result list is
        // limited to 20 only after all matching candidates have been ranked.
        foreach ($query->cursor() as $row) {
            $candidate = Registry::individualFactory()->make($row->i_id, $tree, $row->i_gedcom);
            if (!$candidate instanceof Individual || $candidate->xref() === $source->xref() || !GedcomRecord::accessFilter()($candidate)) {
                continue;
            }

            [$score, $evidence] = $this->score($sourceNames, $sourceBirth, $sourceDeath, $sourceSex, $sourceKeys, $candidate);
            if ($score <= 0) {
                continue;
            }

            $matches[] = [
                'individual' => $candidate,
                'name' => $this->displayName($candidate),
                'birth' => $this->dateValue($candidate, 'BIRT'),
                'death' => $this->dateValue($candidate, 'DEAT'),
                'sex' => $candidate->sex(),
                'score' => $score,
                'evidence' => $evidence,
                'external_keys' => $this->externalKeys($candidate),
            ];
        }

        usort($matches, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score']) ?: strcasecmp($left['name'], $right['name']);
        });

        return array_slice($matches, 0, $limit);
    }

    /** @return list<string> */
    private function names(Individual $individual): array
    {
        $names = [];
        foreach ($individual->getAllNames() as $name) {
            $value = $name['fullNN'] ?? $name['full'] ?? '';
            $value = $this->normalize((string) $value);
            if ($value !== '') {
                $names[] = $value;
            }
        }

        return array_values(array_unique($names));
    }

    private function displayName(Individual $individual): string
    {
        $names = $individual->getAllNames();
        $primary = $names[$individual->getPrimaryName()] ?? reset($names);

        return trim(strip_tags((string) ($primary['fullNN'] ?? $primary['full'] ?? $individual->xref())));
    }

    /** @return array{0:int,1:list<string>} */
    private function score(array $sourceNames, string $sourceBirth, string $sourceDeath, string $sourceSex, array $sourceKeys, Individual $candidate): array
    {
        $score = 0;
        $evidence = [];
        $candidateNames = $this->names($candidate);

        foreach ($candidateNames as $candidateName) {
            foreach ($sourceNames as $sourceName) {
                if ($candidateName === $sourceName) {
                    $score = max($score, 60);
                    $evidence[] = (string) I18N::translate('The normalized name matches.');
                } else {
                    $sourceTokens = array_unique(preg_split('/\s+/', $sourceName) ?: []);
                    $candidateTokens = array_unique(preg_split('/\s+/', $candidateName) ?: []);
                    $overlap = count(array_intersect($sourceTokens, $candidateTokens));
                    $score = max($score, min(40, $overlap * 20));
                    if ($overlap > 0) {
                        $evidence[] = (string) I18N::translate('The names share normalized words.');
                    }
                }
            }
        }

        $candidateBirth = $this->dateValue($candidate, 'BIRT');
        $candidateDeath = $this->dateValue($candidate, 'DEAT');
        $score += $this->dateScore($sourceBirth, $candidateBirth, MoreI18N::xlate('Birth'), $evidence);
        $score += $this->dateScore($sourceDeath, $candidateDeath, MoreI18N::xlate('Death'), $evidence);

        if ($sourceSex !== 'U' && $candidate->sex() !== 'U') {
            if ($sourceSex === $candidate->sex()) {
                $score += 10;
                $evidence[] = (string) I18N::translate('The sex matches.');
            } else {
                $score -= 10;
                $evidence[] = (string) I18N::translate('The sex differs.');
            }
        }

        $sharedKeys = array_intersect($sourceKeys, $this->externalKeys($candidate));
        if ($sharedKeys !== []) {
            $score += 100;
            $evidence[] = (string) I18N::translate('An external identifier matches.');
        }

        return [$score, array_values(array_unique($evidence))];
    }

    /** @param list<string> $evidence */
    private function dateScore(string $source, string $candidate, string $label, array &$evidence): int
    {
        if ($source === '' || $candidate === '') {
            return 0;
        }

        $sourceYear = $this->year($source);
        $candidateYear = $this->year($candidate);
        if ($sourceYear === null || $candidateYear === null) {
            return 0;
        }
        if ($sourceYear === $candidateYear) {
            $evidence[] = (string) I18N::translate('The year of %s matches.', $label);
            return 20;
        }

        $evidence[] = (string) I18N::translate('The year of %s differs.', $label);
        return -20;
    }

    private function dateValue(Individual $individual, string $tag): string
    {
        return trim($individual->facts([$tag])->first()?->attribute('DATE') ?? '');
    }

    private function year(string $date): ?int
    {
        return preg_match('/\b(\d{4})\b/', $date, $match) === 1 ? (int) $match[1] : null;
    }

    private function normalize(string $value): string
    {
        $value = I18N::language()->normalize(strip_tags($value));
        $value = mb_strtolower(str_replace('/', ' ', $value));

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    private function externalKeys(Individual $individual): array
    {
        return array_values(array_unique(array_map(
            static fn ($identifier): string => $identifier->provider . ':' . $identifier->value,
            (new ExternalProviderRegistry())->parse($individual->gedcom()),
        )));
    }
}
