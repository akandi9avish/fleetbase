<?php

namespace Reeup\Integration\Auth\Schemas;

/**
 * REEUP Custom IAM Roles Schema
 *
 * Defines entity-type-specific roles for the REEUP cannabis retail platform.
 * These roles map to entity types and provide appropriate permissions for each business type.
 *
 * Role Mapping (from backend_api entity_types):
 * - entity_type_id 1 (cultivator) → Reeup Cultivator
 * - entity_type_id 2 (processor) → Reeup Processor
 * - entity_type_id 3 (retailer) → Reeup Retailer
 * - entity_type_id 4 (distributor) → Reeup Distributor
 * - entity_type_id 5 (microbusiness) → Reeup Microbusiness
 * - entity_type_id 6 (admin) → Administrator (using built-in admin role)
 */
class Reeup
{
    /**
     * The permission schema Name.
     */
    public string $name = 'reeup';

    /**
     * The permission schema Policy Name.
     */
    public string $policyName = 'REEUP';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['sanctum'];

    /**
     * Direct permissions for the schema.
     */
    public array $permissions = [
        'view-delivery-dashboard',
        'manage-orders',
        'track-deliveries',
        'manage-drivers',
    ];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'delivery',
            'actions' => ['create', 'update', 'view', 'list', 'delete', 'track', 'assign'],
        ],
        [
            'name'    => 'driver',
            'actions' => ['create', 'update', 'view', 'list', 'delete', 'assign', 'track'],
        ],
        [
            'name'    => 'vehicle',
            'actions' => ['create', 'update', 'view', 'list', 'delete'],
        ],
    ];

    /**
     * Policies provided by this schema.
     */
    public array $policies = [
        [
            'name'        => 'ReeupRetailOperations',
            'description' => 'Policy for retail dispensary operations including delivery management.',
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'manage-orders',
                'track-deliveries',
                '* delivery',
                'view driver',
                'list driver',
            ],
        ],
        [
            'name'        => 'ReeupDistributionOperations',
            'description' => 'Policy for distribution operations including driver and vehicle management.',
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'manage-orders',
                'track-deliveries',
                'manage-drivers',
                '* delivery',
                '* driver',
                '* vehicle',
            ],
        ],
        [
            'name'        => 'ReeupCultivationOperations',
            'description' => 'Policy for cultivation operations with delivery tracking.',
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'track-deliveries',
                'view delivery',
                'list delivery',
            ],
        ],
        [
            'name'        => 'ReeupProcessingOperations',
            'description' => 'Policy for processing operations with delivery tracking.',
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'track-deliveries',
                'view delivery',
                'list delivery',
            ],
        ],
    ];

    /**
     * Roles provided by this schema.
     *
     * These roles are assigned based on entity_type_id from backend_api.
     */
    public array $roles = [
        [
            'name'        => 'Reeup Retailer',
            'description' => 'Role for retail dispensary operators. Manages deliveries, tracks orders, and coordinates with drivers.',
            'policies'    => [
                'ReeupRetailOperations',
            ],
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'manage-orders',
                'track-deliveries',
            ],
        ],
        [
            'name'        => 'Reeup Distributor',
            'description' => 'Role for distribution operators. Full access to delivery management, driver assignment, and fleet operations.',
            'policies'    => [
                'ReeupDistributionOperations',
            ],
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'manage-orders',
                'track-deliveries',
                'manage-drivers',
            ],
        ],
        [
            'name'        => 'Reeup Cultivator',
            'description' => 'Role for cultivation operators. Can track deliveries for product shipments.',
            'policies'    => [
                'ReeupCultivationOperations',
            ],
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'track-deliveries',
            ],
        ],
        [
            'name'        => 'Reeup Processor',
            'description' => 'Role for processing operators. Can track deliveries for processed products.',
            'policies'    => [
                'ReeupProcessingOperations',
            ],
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'track-deliveries',
            ],
        ],
        [
            'name'        => 'Reeup Microbusiness',
            'description' => 'Role for microbusiness operators. Combined permissions for cultivation, processing, and retail operations.',
            'policies'    => [
                'ReeupRetailOperations',
                'ReeupCultivationOperations',
            ],
            'permissions' => [
                'see extension',
                'view-delivery-dashboard',
                'manage-orders',
                'track-deliveries',
            ],
        ],
    ];
}
