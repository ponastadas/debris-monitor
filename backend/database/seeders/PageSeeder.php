<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $page) {
            Page::updateOrCreate(
                ['slug' => $page['slug']],
                $page,
            );
        }
    }

    private function pages(): array
    {
        $now = now();

        return [
            [
                'title'            => 'Privacy Policy',
                'slug'             => 'privacy-policy',
                'excerpt'          => 'How Debris Monitor collects, uses, and protects your personal data.',
                'meta_title'       => 'Privacy Policy — Debris Monitor',
                'meta_description' => 'Debris Monitor privacy policy: data collected, how it is used, your rights under GDPR, and how to contact us.',
                'status'           => 'published',
                'published_at'     => $now,
                'content'          => <<<'MD'
# Privacy Policy

**Last updated:** April 2026

This Privacy Policy explains how Debris Monitor ("we", "us", "our") collects, uses, and protects your personal data when you use our satellite conjunction monitoring platform at debris-monitor.com (the "Service").

We are committed to protecting your privacy and processing your data lawfully. This policy applies to all users, including free and paid accounts, API users, and website visitors.

---

## 1. Who We Are

Debris Monitor is a satellite conjunction risk monitoring platform. For data protection purposes, Debris Monitor is the data controller responsible for your personal data.

**Contact:** [Insert company address and registration number]
**Email:** privacy@debris-monitor.com

---

## 2. Data We Collect

### Account data
When you register, we collect: name, email address, and password (stored as a bcrypt hash — never in plain text).

### Usage data
We collect data about how you use the Service: API requests, endpoints accessed, request timestamps, and IP addresses. This data is used to enforce rate limits and detect abuse.

### Billing data
If you subscribe to a paid plan, we collect billing information. Payment processing is handled by Stripe. We store a reference to your Stripe customer ID and a record of transactions (amount, currency, date). Full card details are never stored by us.

### Technical data
We may collect browser type, device type, and referrer URL for analytics purposes (with your consent).

### Cookies
We use strictly necessary cookies for session management. Analytics cookies (Google Analytics 4) are only set with your explicit consent. See our Cookie Policy for details.

---

## 3. How We Use Your Data

| Purpose | Legal basis |
|---|---|
| Providing the Service (API access, conjunction alerts) | Contract performance |
| Account management and authentication | Contract performance |
| Billing and payment processing | Contract performance |
| Security monitoring and fraud prevention | Legitimate interests |
| Service analytics and performance monitoring | Legitimate interests |
| Website analytics (GA4) | Consent |
| Sending service-related emails | Contract performance |
| Legal and regulatory compliance | Legal obligation |

---

## 4. Data Retention

- **Account data**: retained while your account is active, and for up to 3 years after closure for legal compliance
- **API usage logs**: retained for 12 months, then anonymised
- **Payment records**: retained for 7 years as required by financial regulations
- **Backups**: encrypted backups are retained for 30 days then permanently deleted

---

## 5. Your Rights (GDPR)

If you are located in the EU or UK, you have the following rights:

- **Right of access**: request a copy of the data we hold about you
- **Right to rectification**: request correction of inaccurate data
- **Right to erasure**: request deletion of your data ("right to be forgotten")
- **Right to restriction**: request that we limit processing of your data
- **Right to data portability**: receive your data in a structured, machine-readable format
- **Right to object**: object to processing based on legitimate interests
- **Right to withdraw consent**: withdraw analytics consent at any time via the cookie settings

To exercise any of these rights, email privacy@debris-monitor.com. We will respond within 30 days. You also have the right to lodge a complaint with your national data protection authority.

---

## 6. Third Parties

We use the following third-party processors:

| Processor | Purpose | Location |
|---|---|---|
| Stripe | Payment processing | USA (Privacy Shield / SCCs) |
| Google Analytics 4 | Website analytics (consent-based) | USA (SCCs) |
| [Hosting provider] | Infrastructure | [Location] |

We do not sell your personal data to any third party.

---

## 7. International Transfers

Some of our service providers process data outside the EEA. Where this occurs, we ensure appropriate safeguards are in place (Standard Contractual Clauses or equivalent).

---

## 8. Security

We implement appropriate technical and organisational measures to protect your data, including encrypted data transmission (TLS), encrypted storage of sensitive fields, access controls, and regular security reviews.

---

## 9. Changes to This Policy

We may update this policy periodically. We will notify you of significant changes by email or by posting a notice on the Service.

---

## 10. Contact

For privacy-related questions or to exercise your rights, contact us at:
**privacy@debris-monitor.com**
MD,
            ],

            [
                'title'            => 'Cookie Policy',
                'slug'             => 'cookie-policy',
                'excerpt'          => 'What cookies Debris Monitor uses and how to manage your preferences.',
                'meta_title'       => 'Cookie Policy — Debris Monitor',
                'meta_description' => 'Debris Monitor cookie policy: which cookies are used, their purpose, duration, and how to manage your preferences.',
                'status'           => 'published',
                'published_at'     => $now,
                'content'          => <<<'MD'
# Cookie Policy

**Last updated:** April 2026

This Cookie Policy explains how Debris Monitor uses cookies and similar technologies on our website.

---

## What Are Cookies?

Cookies are small text files stored on your device by your browser when you visit a website. They are widely used to make websites work more efficiently and to provide information to site owners.

---

## Cookies We Use

### Strictly Necessary Cookies

These cookies are essential for the Service to function. They do not require your consent and cannot be disabled.

| Cookie | Purpose | Duration |
|---|---|---|
| `dm_token` | Stores your authentication token to keep you logged in | Session |
| `dm_guest_id` | Anonymous session identifier for rate limiting guests | 1 year |

### Analytics Cookies (consent required)

These cookies collect anonymous information about how visitors use our website. We only set them with your explicit consent.

| Cookie | Provider | Purpose | Duration |
|---|---|---|---|
| `_ga` | Google Analytics 4 | Distinguishes unique users | 2 years |
| `_ga_*` | Google Analytics 4 | Session state | 2 years |

### Marketing Cookies (consent required)

We do not currently use marketing or advertising cookies. This section is reserved for future use.

---

## Cookie Consent

When you first visit our website, we show you a cookie banner. You can:

- **Accept All** — allow all cookie categories
- **Reject Non-Essential** — only strictly necessary cookies are set
- **Customize** — choose which categories to allow

You can change your preferences at any time using the **Cookie Settings** link in the site footer.

Your consent choices are stored in your browser's local storage (not as a cookie itself) under the key `dm_cookie_consent`.

---

## How to Manage Cookies

You can also manage cookies directly in your browser:

- **Chrome**: Settings → Privacy and Security → Cookies and other site data
- **Firefox**: Settings → Privacy & Security → Cookies and Site Data
- **Safari**: Preferences → Privacy → Manage Website Data
- **Edge**: Settings → Cookies and Site Permissions

Note that disabling strictly necessary cookies will affect the functionality of the Service.

---

## Contact

For questions about our cookie use, email: privacy@debris-monitor.com
MD,
            ],

            [
                'title'            => 'Terms of Service',
                'slug'             => 'terms',
                'excerpt'          => 'The terms and conditions governing your use of Debris Monitor.',
                'meta_title'       => 'Terms of Service — Debris Monitor',
                'meta_description' => 'Read the Debris Monitor Terms of Service: account responsibilities, API usage, billing, and prohibited uses.',
                'status'           => 'published',
                'published_at'     => $now,
                'content'          => <<<'MD'
# Terms of Service

**Last updated:** April 2026

Please read these Terms of Service ("Terms") carefully before using the Debris Monitor satellite conjunction monitoring platform ("Service") operated by Debris Monitor ("we", "us", "our").

By accessing or using the Service, you agree to be bound by these Terms.

---

## 1. Acceptance of Terms

By registering for an account or using the Service in any way, you confirm that you:
- are at least 18 years old
- have the authority to enter into these Terms
- agree to comply with these Terms and all applicable laws

---

## 2. Description of Service

Debris Monitor provides satellite conjunction risk monitoring data, API access, and related tools. The Service uses publicly available orbital data from sources including CelesTrak and Space-Track.

**Data disclaimer**: Orbital data and conjunction risk assessments are provided for informational purposes only. They should not be relied upon as the sole basis for operational space safety decisions.

---

## 3. Account Responsibilities

You are responsible for:
- maintaining the confidentiality of your account credentials
- all activity that occurs under your account
- ensuring your account information is accurate and up to date
- notifying us immediately of any unauthorised use

You may not share your account or API keys with third parties beyond what your plan permits.

---

## 4. API Usage

Your use of the API is subject to the rate limits and capabilities of your selected plan. You may not:
- attempt to circumvent rate limits or access controls
- use the API to build a competing service that re-sells our data without permission
- use automated scraping outside of the documented API
- exceed the API key limits of your plan tier

---

## 5. Prohibited Uses

You may not use the Service to:
- violate any applicable law or regulation
- transmit malware, spam, or other harmful content
- attempt to gain unauthorised access to our systems or other users' accounts
- conduct activities that could damage, disable, or impair the Service
- interfere with other users' use of the Service

---

## 6. Billing and Payments

### Subscriptions
Paid plans are billed on a monthly basis. By subscribing, you authorise us to charge your payment method on a recurring basis.

### Cancellation
You may cancel your subscription at any time. Cancellation takes effect at the end of the current billing period. We do not offer refunds for partial months.

### Price changes
We will give you at least 30 days' notice of any price change before it takes effect.

### Failed payments
If payment fails, we may downgrade or suspend your account until payment is resolved.

---

## 7. Termination

We reserve the right to suspend or terminate your account at our sole discretion if we believe you have violated these Terms, without prior notice. You may delete your account at any time via your account settings.

Upon termination, your right to use the Service ceases immediately. We may retain data for the period required by law.

---

## 8. Intellectual Property

The Service, including all content, code, and branding, is owned by Debris Monitor and protected by applicable intellectual property laws. You are granted a limited, non-exclusive, non-transferable licence to use the Service in accordance with these Terms.

---

## 9. Disclaimer of Warranties

THE SERVICE IS PROVIDED "AS IS" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR THAT DATA WILL BE ACCURATE OR COMPLETE.

---

## 10. Limitation of Liability

TO THE MAXIMUM EXTENT PERMITTED BY LAW, WE SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING LOSS OF PROFITS, DATA, OR GOODWILL, ARISING FROM YOUR USE OF THE SERVICE.

OUR TOTAL LIABILITY SHALL NOT EXCEED THE AMOUNT YOU PAID US IN THE 12 MONTHS PRECEDING THE CLAIM.

---

## 11. Governing Law

These Terms shall be governed by and construed in accordance with the laws of [Jurisdiction]. Any disputes shall be subject to the exclusive jurisdiction of the courts of [Jurisdiction].

---

## 12. Changes to Terms

We may modify these Terms at any time. We will notify you of significant changes via email. Continued use of the Service after changes take effect constitutes acceptance of the updated Terms.

---

## 13. Contact

For questions about these Terms, contact: legal@debris-monitor.com
MD,
            ],

            [
                'title'            => 'About',
                'slug'             => 'about',
                'excerpt'          => 'What Debris Monitor is, why it exists, and how it works.',
                'meta_title'       => 'About Debris Monitor',
                'meta_description' => 'Debris Monitor provides real-time satellite conjunction risk data via a clean API for developers and space startups.',
                'status'           => 'published',
                'published_at'     => $now,
                'content'          => <<<'MD'
# About Debris Monitor

Debris Monitor is a satellite conjunction risk monitoring platform designed for developers, researchers, and space startups who need programmatic access to orbital safety data.

---

## The Problem

Low Earth Orbit is getting crowded. As the number of active satellites and tracked debris objects grows, conjunction events — close approaches between orbiting objects — are increasingly frequent. Understanding and monitoring these risks requires access to current orbital data and reliable risk scoring.

Existing tools are either gated behind expensive enterprise contracts, too complex for developer use, or lack proper API access. Debris Monitor fills that gap.

---

## What We Provide

- **Conjunction risk analysis** — for any tracked NORAD object, we surface nearby objects with probability estimates and miss distance data
- **Satellite tracking** — live position and orbital path data using SGP4 propagation
- **Conjunction alerts** — automated notifications when your tracked satellites have upcoming close approaches
- **Clean REST API** — documented, rate-limited, available on freemium and paid plans

---

## Data Sources

Orbital data comes from publicly available sources:

- **CelesTrak** — TLE (Two-Line Element) sets updated regularly
- **Space-Track.org** — Full catalog and Conjunction Data Messages (CDM) — Phase 2

We do not generate or certify this data. It is sourced from publicly operated services and provided as-is for informational use.

---

## Our Approach

We built Debris Monitor with a developer-first mindset:
- OpenAPI-documented endpoints
- Freemium access (10 analyses/day without an account)
- Tiered API keys for integration into your own applications
- Commercial use permitted

---

## Contact

Got a question, found a bug, or want to discuss an enterprise plan?

Email: hello@debris-monitor.com
MD,
            ],

            [
                'title'            => 'Contact',
                'slug'             => 'contact',
                'excerpt'          => 'Get in touch with the Debris Monitor team.',
                'meta_title'       => 'Contact — Debris Monitor',
                'meta_description' => 'Contact the Debris Monitor team for support, enterprise enquiries, or general questions.',
                'status'           => 'published',
                'published_at'     => $now,
                'content'          => <<<'MD'
# Contact

We are happy to hear from you.

---

## General Enquiries

**Email:** hello@debris-monitor.com

For general questions about the platform, partnership enquiries, or feedback.

---

## Technical Support

**Email:** support@debris-monitor.com

For questions about the API, data issues, or account problems. Please include your account email and a description of the issue.

---

## Privacy and Data Requests

**Email:** privacy@debris-monitor.com

For data subject requests (GDPR access, deletion, portability), or questions about our privacy practices.

---

## Enterprise Plans

If you need higher rate limits, custom integrations, or a tailored data agreement, contact us at hello@debris-monitor.com with a brief description of your use case.

---

## Response Times

We aim to respond to all enquiries within **2 business days**.

---

*Debris Monitor is a product of [Company Name], registered at [Address].*
MD,
            ],
        ];
    }
}
