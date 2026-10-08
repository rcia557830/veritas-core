<?php

return [
    'permissions' => [
        'client.view', 'client.create', 'client.update', 'client.assign', 'client.archive', 'client.restore',
        'document.view', 'document.upload', 'document.update', 'document.validate', 'document.approve', 'document.reject', 'document.download', 'document.archive',
        'bookkeeping.view', 'bookkeeping.create', 'bookkeeping.update', 'bookkeeping.submit', 'bookkeeping.review', 'bookkeeping.approve', 'bookkeeping.delete',
        'compliance.view', 'compliance.create', 'compliance.update', 'compliance.assign', 'compliance.file', 'compliance.archive',
        'notice.view', 'notice.create', 'notice.update', 'notice.publish', 'notice.archive',
        'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment', 'billing.cancel',
        'knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.publish', 'knowledge.archive',
        'report.view', 'report.generate', 'report.export', 'report.print',
        'user.view', 'user.create', 'user.update', 'user.role', 'user.activate', 'user.deactivate', 'user.reset-password',
        'workspace.manage', 'audit.view',
    ],
    'roles' => ['owner' => 'Owner', 'bookkeeper' => 'Bookkeeper', 'office-manager' => 'Office Manager'],
    'grants' => [
        'bookkeeper' => [
            'client.view', 'client.create', 'client.update',
            'document.view', 'document.upload', 'document.update', 'document.download',
            'bookkeeping.view', 'bookkeeping.create', 'bookkeeping.update', 'bookkeeping.submit', 'bookkeeping.delete',
            'compliance.view', 'compliance.update',
            'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment',
            'knowledge.view', 'knowledge.create', 'knowledge.update',
            'report.view', 'report.generate', 'report.export', 'report.print',
        ],
        'office-manager' => [
            'client.view', 'client.create', 'client.update', 'client.assign', 'client.archive', 'client.restore',
            'document.view', 'document.upload', 'document.update', 'document.validate', 'document.approve', 'document.reject', 'document.download',
            'bookkeeping.view', 'bookkeeping.review', 'bookkeeping.approve',
            'compliance.view', 'compliance.create', 'compliance.update', 'compliance.assign', 'compliance.file',
            'notice.view', 'notice.create', 'notice.update', 'notice.publish', 'notice.archive',
            'billing.view', 'billing.create', 'billing.update', 'billing.issue', 'billing.payment',
            'knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.publish', 'knowledge.archive',
            'report.view', 'report.generate', 'report.export', 'report.print',
        ],
    ],
];
