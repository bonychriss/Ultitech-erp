<?php

declare(strict_types=1);

/**
 * Questions and answers on the public FAQ page (faq.php). Also used for the FAQPage search schema
 * and the plain-HTML copy crawlers see before JavaScript runs, so keep answers as plain text.
 */

if (!function_exists('erp_site_faq')) {
    /**
     * @return list<array{group:string,question:string,answer:string}>
     */
    function erp_site_faq(): array
    {
        return [
            [
                'group' => 'Getting started',
                'question' => 'What is UltiTech ERP?',
                'answer' => 'UltiTech ERP is a cloud business system that brings sales, stock, payroll, expenses and accounting into one place, with live reports for every department. It is built in Dar es Salaam for growing businesses in Tanzania.',
            ],
            [
                'group' => 'Getting started',
                'question' => 'How does the free trial work?',
                'answer' => 'Sign up on the Free Trial page and you get 14 days of full access to every module. No card is needed to start.',
            ],
            [
                'group' => 'Getting started',
                'question' => 'What happens when my trial ends?',
                'answer' => 'Contact the UltiTech team by phone, WhatsApp or email and we will help you choose a plan so you can keep working in your account.',
            ],
            [
                'group' => 'Getting started',
                'question' => 'Do I need to install anything?',
                'answer' => 'No. UltiTech ERP runs in a web browser, so you can sign in from a computer, tablet or phone with an internet connection.',
            ],
            [
                'group' => 'Features',
                'question' => 'Which modules are included?',
                'answer' => 'Sales (quotations, orders, invoices and customers), stock and inventory, purchases, payroll, expenses and payment vouchers, cash book, accounting and reports, CRM, deliveries, attendance and letters.',
            ],
            [
                'group' => 'Features',
                'question' => 'Can I manage more than one company?',
                'answer' => 'Yes. UltiTech ERP supports several companies under one login, and each company keeps its own data, staff and settings.',
            ],
            [
                'group' => 'Features',
                'question' => 'Can I control what each staff member can see?',
                'answer' => 'Yes. Access is role-based, so you decide which modules each user can open, and payment requests can go through an approval process before they are paid.',
            ],
            [
                'group' => 'Features',
                'question' => 'Can I bring in my existing products and data?',
                'answer' => 'Yes. Products and stock can be imported from a spreadsheet, and our team can help you move other records during setup.',
            ],
            [
                'group' => 'Support',
                'question' => 'How do I get help?',
                'answer' => 'Call, WhatsApp or email the UltiTech team in Dar es Salaam. Our contact details and opening hours are on the Contact page.',
            ],
            [
                'group' => 'Support',
                'question' => 'Can I see a demo before signing up?',
                'answer' => 'Yes. Contact us to book a demo for your team, or start the free trial and explore the system yourself.',
            ],
        ];
    }
}
