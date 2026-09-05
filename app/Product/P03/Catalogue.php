<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * The P03 customer catalogue — what the tenant actually sells.
 *
 * THIS IS THE MVP AUTHORITY FOR PRODUCTS AND OFFERS. The P02 offering-twin path exists upstream and
 * is deliberately NOT ported here: this projection has one place a product is defined, and having
 * two would make "what does this customer sell?" a question with two answers that could disagree.
 *
 * A catalogue is not free-floating data. It rides an M05 source reference and an approved licence
 * card, exactly like a competitor source does — because a customer's own product list is still
 * material the product reasons over, and material with no provenance is material nobody can check.
 * A tenant-owned catalogue's licence is usually permissive, but "usually permissive" is not the
 * same as "unexamined".
 */
final class Catalogue
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Register a catalogue against its M05 source reference and approved card.
     *
     * The same three authorities a monitored source needs, for the same reason: the reference must
     * be capturable, the card must be approved, and the card must describe THAT reference.
     */
    public function registerCatalogue(
        string $tenantId,
        ?string $workspaceId,
        string $catalogueKey,
        string $displayName,
        string $sourceRefId,
        string $sourceLicenseCardId,
        string $status = 'ACTIVE',
    ): Outcome {
        $card = $this->db->one(
            'SELECT tenant_id, source_ref_id, review_status FROM source_license_cards
             WHERE source_license_card_id = ?',
            [$sourceLicenseCardId]
        );
        if ($card === null) {
            return Outcome::refuse('SOURCE_CARD_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $card['tenant_id'] !== $tenantId) {
            return Outcome::refuse('SOURCE_CARD_TENANT_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $card['review_status'] !== 'approved') {
            return Outcome::refuse('SOURCE_CARD_NOT_APPROVED', [
                'review_status' => (string) $card['review_status'],
            ], 'POLICY');
        }
        // The card must describe THIS reference. Otherwise an approved card for one source could
        // stand as the authority for a catalogue drawn from another.
        if ((string) $card['source_ref_id'] !== $sourceRefId) {
            return Outcome::refuse('SOURCE_CARD_REFERENCE_MISMATCH', [], 'PROVENANCE', 'S4');
        }

        $reference = $this->db->one(
            'SELECT status FROM source_references WHERE source_ref_id = ?',
            [$sourceRefId]
        );
        if ($reference === null) {
            return Outcome::refuse('SOURCE_REFERENCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $reference['status'] !== 'ACTIVE_FOR_CAPTURE') {
            return Outcome::refuse('SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE', [
                'status' => (string) $reference['status'],
            ], 'POLICY');
        }

        $catalogueId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $catalogueId, $tenantId, $workspaceId, $catalogueKey, $displayName,
            $sourceRefId, $sourceLicenseCardId, $status, $now
        ): Outcome {
            $tx->insert('customer_product_catalogues', [
                'customer_product_catalogue_id' => $catalogueId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'catalogue_key' => $catalogueKey,
                'display_name' => $displayName,
                'source_ref_id' => $sourceRefId,
                'source_license_card_id' => $sourceLicenseCardId,
                'catalogue_status' => $status,
                'created_at' => $now,
            ]);
            $tx->insert('customer_product_catalogue_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'customer_product_catalogue_id' => $catalogueId,
                'from_status' => null,
                'to_status' => $status,
                'reason_code' => 'REGISTERED',
                'transitioned_at' => $now,
            ]);

            return Outcome::ok(['customer_product_catalogue_id' => $catalogueId]);
        });
    }

    /**
     * Add a product or offer to a catalogue.
     *
     * `attributes` are the terms fit scoring matches against — what the product IS, in the tenant's
     * own words. They are stored as a list rather than free prose because the scorer tokenizes them
     * and a tenant needs to be able to see exactly which words their matching rests on.
     *
     * @param list<string> $attributes
     */
    public function addProduct(
        string $catalogueId,
        string $productKey,
        string $displayName,
        string $productOfferKind,
        array $attributes,
        ?string $valuePropositionSafe = null,
        string $status = 'ACTIVE',
    ): Outcome {
        $catalogue = $this->db->one(
            'SELECT tenant_id, catalogue_status FROM customer_product_catalogues
             WHERE customer_product_catalogue_id = ?',
            [$catalogueId]
        );
        if ($catalogue === null) {
            return Outcome::refuse('CATALOGUE_NOT_FOUND', [], 'PROVENANCE');
        }
        // A product added to a suspended or retired catalogue would be immediately unusable, and
        // the refusal says so now rather than letting it be discovered at pairing time.
        if ((string) $catalogue['catalogue_status'] !== 'ACTIVE') {
            return Outcome::refuse('CATALOGUE_NOT_ACTIVE', [
                'catalogue_status' => (string) $catalogue['catalogue_status'],
            ], 'POLICY');
        }
        if ($attributes === []) {
            // A product with no attributes cannot be matched against anything. Recording one would
            // create a catalogue entry that silently never produces a hypothesis.
            return Outcome::refuse('PRODUCT_ATTRIBUTES_REQUIRED', [], 'POLICY');
        }

        $productId = Ids::new();
        $this->db->insert('customer_products', [
            'customer_product_id' => $productId,
            'schema_version' => '1',
            'tenant_id' => (string) $catalogue['tenant_id'],
            'customer_product_catalogue_id' => $catalogueId,
            'product_key' => $productKey,
            'display_name' => $displayName,
            'product_offer_kind' => $productOfferKind,
            'value_proposition_safe' => $valuePropositionSafe,
            'attributes_json' => json_encode(array_values($attributes), JSON_THROW_ON_ERROR),
            'product_status' => $status,
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['customer_product_id' => $productId]);
    }

    /**
     * Change a catalogue's status, recording the transition.
     *
     * Catalogue status is read at PAIRING time, so suspending one immediately stops new hypotheses
     * being derived from it — which is what makes this a real control rather than a label.
     */
    public function changeCatalogueStatus(string $catalogueId, string $toStatus, string $reason): Outcome
    {
        $catalogue = $this->db->one(
            'SELECT catalogue_status FROM customer_product_catalogues WHERE customer_product_catalogue_id = ?',
            [$catalogueId]
        );
        if ($catalogue === null) {
            return Outcome::refuse('CATALOGUE_NOT_FOUND', [], 'PROVENANCE');
        }
        $from = (string) $catalogue['catalogue_status'];
        if ($from === $toStatus) {
            return Outcome::ok(['from_status' => $from, 'to_status' => $toStatus, 'outcome' => 'UNCHANGED']);
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($catalogueId, $from, $toStatus, $reason, $now): Outcome {
            $tx->run(
                'UPDATE customer_product_catalogues SET catalogue_status = ?
                 WHERE customer_product_catalogue_id = ? AND catalogue_status = ?',
                [$toStatus, $catalogueId, $from]
            );
            $tx->insert('customer_product_catalogue_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'customer_product_catalogue_id' => $catalogueId,
                'from_status' => $from,
                'to_status' => $toStatus,
                'reason_code' => $reason,
                'transitioned_at' => $now,
            ]);

            return Outcome::ok(['from_status' => $from, 'to_status' => $toStatus, 'outcome' => 'CHANGED']);
        });
    }
}
