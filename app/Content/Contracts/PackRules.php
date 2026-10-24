<?php

namespace App\Content\Contracts;

use App\Content\ContentPack;
use App\Content\ScopingResult;
use App\Content\VersionContext;
use App\Models\InventoryItem;

/**
 * The computational know-how of a content pack. The private cetome pack
 * implements this in its own rules.php; the engine never hard-codes it.
 */
interface PackRules
{
    /**
     * Which RED articles and standards apply, and whether self-assessment is possible.
     *
     * @param  array<string, mixed>  $answers  scoping question key => value
     */
    public function scoping(array $answers, ContentPack $pack): ScopingResult;

    /**
     * The objects a requirement's decision tree must be walked for, in one standard.
     * Return [null] for a product-level requirement, [] when it does not apply.
     *
     * @return list<InventoryItem|null>
     */
    public function evaluationTargets(string $standard, string $requirement, VersionContext $context): array;

    /**
     * The E.Info items that must be documented for one object (or the product, with null).
     *
     * @return list<string> E.Info codes
     */
    public function requiredEinfo(string $standard, ?InventoryItem $item, VersionContext $context): array;

    /**
     * The optional E.Info items of one object (or the product, with null): items the standard
     * defines that the pack's method does not require (e.g. a condition it cannot decide). The
     * E.Info page shows them as conditional; they never count as missing.
     *
     * @return list<string> E.Info codes, none of them in requiredEinfo()
     */
    public function conditionalEinfo(string $standard, ?InventoryItem $item, VersionContext $context): array;

    /**
     * Walks of a requirement that are not walked because another walk settles them, one per
     * object of that other walk (the "source"): state "fail" when the requirement fails with the
     * source walk (e.g. no secure communication mechanism at all), "unlinked" when the source walk
     * calls for objects of this requirement that the inventory does not list or link yet. The
     * engine shows them next to the walks and never counts them as walks of their own.
     *
     * @return list<array{item: InventoryItem|null, requirement: string, state: string}>
     */
    public function followsFrom(string $standard, string $requirement, VersionContext $context): array;

    /**
     * The objects of the inner "For each" loop of one walk, shown with it (e.g. the communicated
     * assets a secure communication mechanism protects). Empty when the walk has no inner loop.
     *
     * @return list<InventoryItem>
     */
    public function innerTargets(string $standard, string $requirement, ?InventoryItem $item, VersionContext $context): array;

    /**
     * Suggested answers to the conformity questions (the RED restrictions), derived from what the
     * version already records: scoping answers, inventory, decision-tree evaluations. The
     * Conformity step pre-fills the questions not answered yet with them; a saved answer always
     * wins and only saved answers decide the route.
     *
     * Keys are question keys (value: the suggested answer, null when the data does not settle it
     * yet) or group keys (value null: reasons shared by the group's questions, shown once).
     * A group entry with "settled" => true says the data settles that restriction by itself (e.g.
     * no secure update to protect): its questions are not asked, and the engine passes the settled
     * group keys to scoping() as "_settled" so the rules count the restriction as met.
     * Each reason is one short line, already localised; its optional "link" names its source:
     * ['step' => 'scoping'], ['step' => 'inventory', 'list' => 'assets', 'item' => <item id>] or
     * ['step' => 'evaluate', 'standard' => 'en18031-1', 'requirement' => 'AUM-5-1', 'item' => <item id>|null].
     *
     * @return array<string, array{value: bool|null, settled?: bool, because: list<array{text: string, link?: array<string, string|null>}>}>
     */
    public function restrictionSuggestions(VersionContext $context, ScopingResult $scoping): array;
}
