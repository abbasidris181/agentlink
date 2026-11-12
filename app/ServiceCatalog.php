<?php

namespace App;

use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\Models\ServiceRequest;

class ServiceCatalog
{
    /** @return array<string, string> */
    public static function modificationTypes(): array
    {
        return ['name' => 'Name', 'phone' => 'Phone number', 'dob' => 'DOB', 'dob_above_5' => 'DOB above 5 years', 'dob_above_10' => 'DOB above 10 years', 'dob_above_15' => 'DOB above 15 years', 'address' => 'Address', 'gender' => 'Gender'];
    }

    /** @return array<string, string> */
    public static function bvnKinds(): array
    {
        return ['agency' => 'Agency Bvn', 'access' => 'Access Bank', 'uba' => 'Uba Bank', 'zenith' => 'Zenith Bank', 'first' => 'First Bank', 'gtbank' => 'GTBank', 'fidelity' => 'Fidelity Bank', 'stanbic' => 'Stanbic IBTC Bank', 'fcmb' => 'FCMB Bank', 'wema' => 'Wema Bank', 'sterling' => 'Sterling Bank', 'microfinance' => 'Microfinance banks', 'jaiz' => 'Jaiz Bank', 'taj' => 'Taj Bank'];
    }

    /** @return array<string, array{name: string, category: string, initials: string}> */
    public static function dashboardServices(): array
    {
        return [
            'nin-v1' => ['name' => 'NIN-V1', 'category' => 'NIN services', 'initials' => 'N1'],
            'nin-v2' => ['name' => 'NIN-V2', 'category' => 'NIN services', 'initials' => 'N2'],
            'nin-v3' => ['name' => 'NIN-V3', 'category' => 'NIN services', 'initials' => 'N3'],
            'bvn-verification' => ['name' => 'BVN VERIFICATION', 'category' => 'BVN services', 'initials' => 'BV'],
            'personalization' => ['name' => 'PERSONALIZATION', 'category' => 'NIN services', 'initials' => 'PE'],
            'nin-modification' => ['name' => 'NIN MODIFICATION', 'category' => 'NIN services', 'initials' => 'NM'],
            'bvn-modification' => ['name' => 'BVN MODIFICATION', 'category' => 'BVN services', 'initials' => 'BM'],
            'bvn-license-onboarding' => ['name' => 'BVN LICENSE ONBOARDING', 'category' => 'BVN services', 'initials' => 'BL'],
            'ipe-clearing' => ['name' => 'IPE CLEARING', 'category' => 'IPE services', 'initials' => 'IC'],
            'instant-ipe' => ['name' => 'INSTANT-IPE', 'category' => 'IPE services', 'initials' => 'II'],
            'mod-ipe' => ['name' => 'MOD-IPE', 'category' => 'IPE services', 'initials' => 'MI'],
            'validations' => ['name' => 'VALIDATIONS', 'category' => 'Other services', 'initials' => 'VA'],
            'delinking' => ['name' => 'Self-Service', 'category' => 'Other services', 'initials' => 'DE'],
            'bvn retrival' => ['name' => 'BVN RETRIEVAL', 'category' => 'Other services', 'initials' => 'BR'],
        ];
    }

    /** @return array<string, array{label: string, slips: array<string, array{label: string, preview: string, sample: string}>}> */
    public static function slips(): array
    {
        return [
            'nin-slips' => ['label' => 'NIN verification slips', 'slips' => NinVerificationRecord::SLIPS],
            'bvn-slips' => ['label' => 'BVN verification slips', 'slips' => BvnVerificationRecord::SLIPS],
        ];
    }

    /** @return array<string, string> */
    public static function complaintTypes(): array
    {
        return array_map(fn (array $service): string => $service['name'], self::dashboardServices()) + ['other' => 'Other'];
    }

    /** @return array<string, array{label: string, methods: array<string, string>}> */
    public static function requestServices(): array
    {
        $services = array_intersect_key(self::all(), array_flip(ServiceRequest::ADMIN_SERVICES));

        return $services + ['complaints' => ['label' => 'Complains', 'methods' => ['complaint' => 'Complain']]];
    }

    /** @return array<string, array{label: string, methods: array<string, string>}> */
    public static function all(): array
    {
        $ninMethods = ['nin' => 'NIN number', 'phone' => 'Phone', 'demographics' => 'Demographics'];
        $ninModifications = [];
        foreach (['first' => 'First modification', 'second' => 'Second modification'] as $stage => $label) {
            foreach (self::modificationTypes() as $type => $typeLabel) {
                $ninModifications[$stage.'_'.$type] = $label.' — '.$typeLabel;
            }
        }
        $bvnModifications = [];
        foreach (self::bvnKinds() as $kind => $label) {
            foreach (['name' => 'Name', 'dob' => 'DOB', 'phone' => 'Phone number'] as $type => $typeLabel) {
                $bvnModifications[$kind.'_'.$type] = $label.' — '.$typeLabel;
            }
        }

        return [
            'nin-v1' => ['label' => 'NIN V1', 'methods' => $ninMethods],
            'nin-v2' => ['label' => 'NIN V2', 'methods' => $ninMethods],
            'nin-v3' => ['label' => 'NIN V3', 'methods' => $ninMethods],
            'bvn-verification' => ['label' => 'BVN verification', 'methods' => ['bvn' => 'BVN number']],
            'bvn-retrieval' => ['label' => 'BVN retrieval', 'methods' => ['phone' => 'Phone', 'crm' => 'CRM']],
            'ipe-clearing' => ['label' => 'IPE clearing', 'methods' => ['tracking' => 'Tracking ID']],
            'mod-ipe' => ['label' => 'MOD-IPE', 'methods' => ['tracking' => 'Tracking ID']],
            'instant-ipe' => ['label' => 'Instant IPE', 'methods' => ['single' => 'Single ID', 'bulk' => 'Bulk, per tracking ID']],
            'validations' => ['label' => 'Validations', 'methods' => ['nin' => 'NIN number']],
            'delinking' => ['label' => 'Self-Service', 'methods' => ['nin' => 'Delinking', 'email_retrieve' => 'Email retrive']],
            'personalization' => ['label' => 'Personalization', 'methods' => ['tracking' => 'Tracking ID']],
            'nin-modification' => ['label' => 'NIN modification', 'methods' => $ninModifications],
            'bvn-modification' => ['label' => 'BVN modification', 'methods' => $bvnModifications],
            'bvn-license-onboarding' => ['label' => 'BVN license onboarding', 'methods' => ['enrollment' => 'Android BVN enrollment']],
        ];
    }
}
