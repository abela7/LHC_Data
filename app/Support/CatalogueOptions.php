<?php

namespace App\Support;

class CatalogueOptions
{
    public static function familyStatuses(): array
    {
        return ['imported', 'identified', 'researching', 'matched', 'needs_review', 'approved', 'rejected', 'archived'];
    }

    public static function typeStatuses(): array
    {
        return ['draft', 'needs_review', 'approved', 'rejected', 'archived'];
    }

    public static function variantStatuses(): array
    {
        return ['draft', 'inferred', 'matched', 'needs_review', 'approved', 'rejected', 'archived'];
    }

    public static function shopMatchStatuses(): array
    {
        return ['unknown', 'maybe', 'confirmed_yes', 'confirmed_no'];
    }

    public static function confirmationMethods(): array
    {
        return ['shelf_photo', 'physical_check', 'manager_confirmation', 'manual_assumption'];
    }

    public static function sourceTypes(): array
    {
        return ['official_brand', 'authorized_distributor', 'trusted_retailer', 'internal_manual'];
    }

    public static function sourceRoles(): array
    {
        return ['primary', 'secondary', 'image_reference', 'variant_reference', 'manual_reference'];
    }

    public static function sourceTrustStatuses(): array
    {
        return ['unverified', 'pending_review', 'verified', 'trusted', 'rejected', 'internal_confirmed'];
    }
}
