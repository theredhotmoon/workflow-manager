<?php

namespace Database\Seeders;

use App\Models\Procedure;
use App\Models\ProcedureTag;
use App\Models\ProcedureVersion;
use Illuminate\Database\Seeder;

class ProcedureBulkSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = 1; // Assuming user ID 1 exists

        // Get existing tag IDs for random assignment
        $tagIds = ProcedureTag::pluck('id')->toArray();

        $procedures = [
            ['title' => 'Resetting Customer Password', 'purpose' => 'Guide for resetting a customer password via the admin panel.', 'requirements' => 'Admin panel access, customer email verification', 'content' => '<h2>Steps</h2><ol><li>Navigate to Admin Panel → Users</li><li>Search the customer by email or ID</li><li>Click "Reset Password"</li><li>Confirm the action via the verification dialog</li><li>Notify the customer via email</li></ol>'],
            ['title' => 'Processing Refund Requests', 'purpose' => 'How to process and approve customer refund requests.', 'requirements' => 'Payment gateway access, manager approval for refunds > $100', 'content' => '<h2>Refund Process</h2><ol><li>Open the refund queue from Dashboard → Refunds</li><li>Review the customer\'s order and reason</li><li>Check eligibility per refund policy</li><li>Submit the refund via the payment gateway</li><li>Update the ticket status to "Resolved"</li></ol>'],
            ['title' => 'Updating Product Pricing', 'purpose' => 'Steps for changing product prices in the catalog.', 'requirements' => 'Catalog management access, pricing approval from supervisor', 'content' => '<h2>Price Update</h2><ol><li>Go to Products → Catalog</li><li>Find the product to update</li><li>Click "Edit Pricing"</li><li>Enter new price and effective date</li><li>Submit for supervisor approval</li><li>Verify pricing on the storefront after approval</li></ol>'],
            ['title' => 'Setting Up New Employee Account', 'purpose' => 'Create and configure accounts for newly hired employees.', 'requirements' => 'HR system access, IT admin panel access', 'content' => '<h2>New Employee Setup</h2><ol><li>Receive onboarding form from HR</li><li>Create user account in Active Directory</li><li>Assign role and department</li><li>Set up email and communication tools</li><li>Grant access to required internal systems</li><li>Send welcome email with credentials</li></ol>'],
            ['title' => 'Handling Server Alerts', 'purpose' => 'Responding to automated server monitoring alerts.', 'requirements' => 'Server monitoring dashboard access, SSH access to production servers', 'content' => '<h2>Alert Response</h2><ol><li>Check the alert severity and type</li><li>Log in to the monitoring dashboard</li><li>Verify the issue: CPU, memory, disk, or network</li><li>Apply known fixes or escalate to senior engineer</li><li>Document the incident in the log</li></ol>'],
            ['title' => 'Deploying Application Updates', 'purpose' => 'Process for deploying new application versions to production.', 'requirements' => 'CI/CD pipeline access, staging environment verification', 'content' => '<h2>Deployment Steps</h2><ol><li>Ensure all tests pass on staging</li><li>Create a deployment ticket</li><li>Run the CI/CD pipeline for production</li><li>Monitor deployment progress</li><li>Verify functionality on production</li><li>Notify team of successful deployment</li></ol>'],
            ['title' => 'Managing Customer Complaints', 'purpose' => 'Framework for handling and resolving customer complaints.', 'requirements' => 'CRM access, complaint resolution guidelines', 'content' => '<h2>Complaint Handling</h2><ol><li>Acknowledge the complaint within 24 hours</li><li>Log details in the CRM system</li><li>Investigate the root cause</li><li>Propose a resolution to the customer</li><li>Follow up within 48 hours</li><li>Close the case and document learnings</li></ol>'],
            ['title' => 'Backing Up Database', 'purpose' => 'Routine and emergency database backup procedures.', 'requirements' => 'Database admin access, backup storage credentials', 'content' => '<h2>Backup Procedure</h2><ol><li>Verify current backup schedule</li><li>Connect to the database server</li><li>Run the backup command or use the admin tool</li><li>Verify backup integrity</li><li>Transfer to off-site storage</li><li>Log the backup in the ledger</li></ol>'],
            ['title' => 'Configuring Email Templates', 'purpose' => 'Creating and modifying automated email templates.', 'requirements' => 'Email service dashboard access', 'content' => '<h2>Template Configuration</h2><ol><li>Navigate to Email Settings → Templates</li><li>Select the template or create a new one</li><li>Edit the subject, body, and variables</li><li>Preview with sample data</li><li>Save and activate the template</li></ol>'],
            ['title' => 'Generating Monthly Reports', 'purpose' => 'Steps for generating standard monthly business reports.', 'requirements' => 'Reporting dashboard access, data export permissions', 'content' => '<h2>Report Generation</h2><ol><li>Navigate to Reports → Monthly</li><li>Select the reporting period</li><li>Choose metrics and KPIs to include</li><li>Generate the report</li><li>Review for accuracy</li><li>Export as PDF and distribute to stakeholders</li></ol>'],
            ['title' => 'Onboarding New Clients', 'purpose' => 'Steps for onboarding a new client and configuring their account.', 'requirements' => 'CRM access, client onboarding checklist', 'content' => '<h2>Client Onboarding</h2><ol><li>Receive signed contract from sales team</li><li>Create client profile in CRM</li><li>Schedule kickoff meeting</li><li>Configure their account and services</li><li>Provide training materials</li><li>Assign dedicated support contact</li></ol>'],
            ['title' => 'Managing API Keys', 'purpose' => 'Creating, rotating, and revoking API keys.', 'requirements' => 'API management console access, admin privileges', 'content' => '<h2>API Key Management</h2><ol><li>Navigate to Developer Portal → API Keys</li><li>Create a new key or select an existing one</li><li>Set permissions and rate limits</li><li>For rotation: create new key, update integrations, revoke old key</li><li>Document the change in the API log</li></ol>'],
            ['title' => 'Troubleshooting Login Issues', 'purpose' => 'Diagnosing and resolving user login problems.', 'requirements' => 'User management panel access, log viewer', 'content' => '<h2>Login Troubleshooting</h2><ol><li>Verify the user account exists and is active</li><li>Check for account lockout due to failed attempts</li><li>Review authentication logs for error details</li><li>Reset password if necessary</li><li>Verify MFA configuration</li><li>Test login and confirm with user</li></ol>'],
            ['title' => 'Scheduling System Maintenance', 'purpose' => 'Planning and communicating scheduled maintenance windows.', 'requirements' => 'Change management approvals, notification system access', 'content' => '<h2>Maintenance Scheduling</h2><ol><li>Identify the maintenance window</li><li>Submit a change management request</li><li>Notify affected teams and customers 48 hours in advance</li><li>Prepare rollback plans</li><li>Execute maintenance during the window</li><li>Send completion notification</li></ol>'],
            ['title' => 'Handling Data Export Requests', 'purpose' => 'Processing customer data export requests per GDPR.', 'requirements' => 'Data management tools, legal approval for sensitive exports', 'content' => '<h2>Data Export Process</h2><ol><li>Verify the requester identity</li><li>Log the request in the compliance tracker</li><li>Extract the relevant data</li><li>Sanitize and package the export</li><li>Deliver securely to the requester</li><li>Document completion within 30 days</li></ol>'],
            ['title' => 'Updating SSL Certificates', 'purpose' => 'Renewing and installing SSL/TLS certificates.', 'requirements' => 'Certificate authority account, server admin access', 'content' => '<h2>SSL Renewal</h2><ol><li>Check certificate expiry dates</li><li>Generate a new CSR</li><li>Submit to certificate authority</li><li>Download and verify the new certificate</li><li>Install on the web server</li><li>Verify HTTPS functionality</li><li>Update monitoring for next renewal</li></ol>'],
            ['title' => 'Managing Inventory Levels', 'purpose' => 'Monitoring and adjusting product inventory counts.', 'requirements' => 'Warehouse management system access', 'content' => '<h2>Inventory Management</h2><ol><li>Review current stock levels in the WMS</li><li>Compare with minimum threshold values</li><li>Create purchase orders for low stock items</li><li>Process incoming inventory receipts</li><li>Update system counts after physical audits</li></ol>'],
            ['title' => 'Conducting Performance Reviews', 'purpose' => 'Guide for managers conducting quarterly performance reviews.', 'requirements' => 'HR portal access, performance metrics dashboard', 'content' => '<h2>Performance Review Process</h2><ol><li>Pull performance data from the metrics dashboard</li><li>Review self-assessments from the employee</li><li>Collect 360 feedback from peers</li><li>Schedule a 1-on-1 meeting</li><li>Discuss achievements, areas for improvement, and goals</li><li>Submit the review form in HR portal</li></ol>'],
            ['title' => 'Setting Up VPN Access', 'purpose' => 'Configuring VPN access for remote employees.', 'requirements' => 'Network admin panel, VPN server configuration access', 'content' => '<h2>VPN Setup</h2><ol><li>Verify employee authorization for remote access</li><li>Create VPN credentials</li><li>Generate and distribute configuration files</li><li>Guide the employee through client installation</li><li>Test the connection</li><li>Document the setup</li></ol>'],
            ['title' => 'Handling Payment Failures', 'purpose' => 'Steps for diagnosing and resolving failed payment transactions.', 'requirements' => 'Payment gateway dashboard, transaction logs', 'content' => '<h2>Payment Failure Resolution</h2><ol><li>Identify the failed transaction in the gateway</li><li>Check error code and reason</li><li>Verify customer payment method</li><li>Retry if appropriate or request alternative payment</li><li>Update the order status</li><li>Notify the customer</li></ol>'],
            ['title' => 'Creating Marketing Campaigns', 'purpose' => 'Process for setting up and launching marketing email campaigns.', 'requirements' => 'Marketing platform access, approved campaign assets', 'content' => '<h2>Campaign Creation</h2><ol><li>Define campaign objectives and target audience</li><li>Design email content using approved templates</li><li>Set up A/B testing variations</li><li>Configure sending schedule</li><li>Run a test send to the review group</li><li>Launch the campaign</li><li>Monitor performance metrics</li></ol>'],
            ['title' => 'Managing User Permissions', 'purpose' => 'Adding, modifying, and revoking user access permissions.', 'requirements' => 'IAM dashboard access, manager approval', 'content' => '<h2>Permission Management</h2><ol><li>Receive access request or change ticket</li><li>Verify manager approval</li><li>Open IAM dashboard</li><li>Find the user account</li><li>Apply or remove the specified permissions</li><li>Notify the user of the change</li><li>Log the action</li></ol>'],
            ['title' => 'Resolving Shipping Delays', 'purpose' => 'Handling customer inquiries about delayed shipments.', 'requirements' => 'Shipping carrier portal access, order management system', 'content' => '<h2>Shipping Delay Resolution</h2><ol><li>Look up the order and tracking number</li><li>Check the carrier portal for shipment status</li><li>Contact the carrier if status is unclear</li><li>Update the customer with an ETA</li><li>Offer compensation per policy if applicable</li><li>Flag recurring carrier issues</li></ol>'],
            ['title' => 'Configuring Firewall Rules', 'purpose' => 'Adding and modifying network firewall rules.', 'requirements' => 'Firewall admin panel, change management approval', 'content' => '<h2>Firewall Configuration</h2><ol><li>Review the requested rule change</li><li>Verify change management approval</li><li>Back up current firewall configuration</li><li>Implement the rule change</li><li>Test connectivity to verify the rule works</li><li>Document the change</li></ol>'],
            ['title' => 'Processing Employee Leave Requests', 'purpose' => 'Approving or denying employee time-off requests.', 'requirements' => 'HR leave management portal, team calendar', 'content' => '<h2>Leave Request Process</h2><ol><li>Review the leave request in HR portal</li><li>Check team availability for the requested dates</li><li>Verify the employee has sufficient leave balance</li><li>Approve or deny with reason</li><li>Update the team calendar</li><li>Notify the employee</li></ol>'],
            ['title' => 'Monitoring Application Logs', 'purpose' => 'Routine review of application logs for errors and anomalies.', 'requirements' => 'Log aggregation tool access (ELK/Splunk)', 'content' => '<h2>Log Monitoring</h2><ol><li>Open the log aggregation dashboard</li><li>Review error and warning log streams</li><li>Filter by time range and severity</li><li>Investigate unusual patterns</li><li>Create tickets for recurring issues</li><li>Update alerting rules as needed</li></ol>'],
            ['title' => 'Managing DNS Records', 'purpose' => 'Creating and updating DNS records for domains.', 'requirements' => 'DNS management console, domain registrar access', 'content' => '<h2>DNS Management</h2><ol><li>Identify the domain and record type (A, CNAME, MX, etc.)</li><li>Log in to the DNS management console</li><li>Create or modify the record</li><li>Verify TTL settings</li><li>Wait for propagation</li><li>Test resolution using dig or nslookup</li></ol>'],
            ['title' => 'Handling Warranty Claims', 'purpose' => 'Processing customer warranty claims for defective products.', 'requirements' => 'Warranty management system, product database', 'content' => '<h2>Warranty Claim Process</h2><ol><li>Verify the product is within warranty period</li><li>Inspect or request photos of the defect</li><li>Log the claim in the warranty system</li><li>Approve replacement or repair</li><li>Arrange return shipping if needed</li><li>Send the replacement and close the claim</li></ol>'],
            ['title' => 'Setting Up Automated Tests', 'purpose' => 'Configuring CI/CD pipelines to run automated test suites.', 'requirements' => 'CI/CD platform access, repository write access', 'content' => '<h2>Test Automation Setup</h2><ol><li>Define the test suite to automate</li><li>Write or update test configuration files</li><li>Configure the CI/CD pipeline stage</li><li>Set environment variables and secrets</li><li>Run a test pipeline to verify</li><li>Enable automatic triggers on push/PR</li></ol>'],
            ['title' => 'Managing Social Media Responses', 'purpose' => 'Guidelines for responding to customer messages on social media.', 'requirements' => 'Social media management tool access, brand guidelines', 'content' => '<h2>Social Media Response</h2><ol><li>Monitor incoming messages and mentions</li><li>Categorize: support, complaint, praise, spam</li><li>Respond per brand voice guidelines</li><li>Escalate complex issues to the support team</li><li>Log interactions in the CRM</li><li>Report weekly engagement metrics</li></ol>'],
            ['title' => 'Configuring Load Balancers', 'purpose' => 'Setting up and tuning load balancer configurations.', 'requirements' => 'Cloud infrastructure access, networking knowledge', 'content' => '<h2>Load Balancer Setup</h2><ol><li>Determine the traffic distribution strategy</li><li>Configure backend server pools</li><li>Set health check parameters</li><li>Configure SSL termination</li><li>Test with synthetic traffic</li><li>Monitor performance and adjust</li></ol>'],
            ['title' => 'Onboarding Remote Workers', 'purpose' => 'Special onboarding process for remote employees.', 'requirements' => 'HR portal, IT provisioning system, shipping access', 'content' => '<h2>Remote Onboarding</h2><ol><li>Ship hardware and peripherals</li><li>Set up remote access (VPN, SSO)</li><li>Schedule virtual orientation sessions</li><li>Assign a buddy/mentor</li><li>Provide access to collaboration tools</li><li>Check in during first week</li></ol>'],
            ['title' => 'Managing CRM Data Quality', 'purpose' => 'Maintaining accuracy and cleanliness of CRM data.', 'requirements' => 'CRM admin access, data deduplication tools', 'content' => '<h2>Data Quality Process</h2><ol><li>Run monthly duplicate detection reports</li><li>Merge duplicate records</li><li>Validate email addresses and phone numbers</li><li>Archive inactive contacts per retention policy</li><li>Standardize data entry formats</li><li>Train team on data entry best practices</li></ol>'],
            ['title' => 'Troubleshooting Network Connectivity', 'purpose' => 'Diagnosing and fixing network connectivity issues.', 'requirements' => 'Network monitoring tools, switch/router access', 'content' => '<h2>Network Troubleshooting</h2><ol><li>Identify the affected area/users</li><li>Check physical connections and switch ports</li><li>Verify DHCP and DNS configuration</li><li>Run ping/traceroute tests</li><li>Check for bandwidth saturation</li><li>Apply fixes and verify restoration</li></ol>'],
            ['title' => 'Processing Purchase Orders', 'purpose' => 'Creating and approving purchase orders for supplies.', 'requirements' => 'Procurement system access, budget approval', 'content' => '<h2>Purchase Order Process</h2><ol><li>Receive purchase request from department</li><li>Verify budget availability</li><li>Select vendor and negotiate terms</li><li>Create PO in procurement system</li><li>Get required approvals</li><li>Send PO to vendor</li><li>Track delivery</li></ol>'],
            ['title' => 'Managing Content Updates', 'purpose' => 'Updating website and application content.', 'requirements' => 'CMS access, content approval workflow', 'content' => '<h2>Content Update Process</h2><ol><li>Receive content update request</li><li>Review and edit content for accuracy</li><li>Upload to CMS staging environment</li><li>Preview the changes</li><li>Submit for editorial approval</li><li>Publish to production</li><li>Verify on live site</li></ol>'],
            ['title' => 'Handling Account Escalations', 'purpose' => 'Process for escalating critical account issues to management.', 'requirements' => 'Escalation matrix, management contact info', 'content' => '<h2>Escalation Process</h2><ol><li>Assess the issue severity</li><li>Document the timeline and actions taken</li><li>Identify the correct escalation path</li><li>Contact the escalation manager</li><li>Provide a clear summary and impact assessment</li><li>Follow up until resolution</li></ol>'],
            ['title' => 'Configuring Monitoring Alerts', 'purpose' => 'Setting up threshold-based alerts for system monitoring.', 'requirements' => 'Monitoring platform admin access', 'content' => '<h2>Alert Configuration</h2><ol><li>Identify the metric to monitor</li><li>Define thresholds for warning and critical</li><li>Configure notification channels (email, Slack, PagerDuty)</li><li>Set escalation rules</li><li>Test the alert trigger</li><li>Document the alert and response procedure</li></ol>'],
            ['title' => 'Managing Software Licenses', 'purpose' => 'Tracking and renewing software licenses.', 'requirements' => 'License management tool, procurement access', 'content' => '<h2>License Management</h2><ol><li>Maintain inventory of all software licenses</li><li>Track expiry dates and renewal costs</li><li>Initiate renewal 60 days before expiry</li><li>Verify license count vs. active users</li><li>Reclaim unused licenses</li><li>Update the license register</li></ol>'],
            ['title' => 'Running A/B Tests', 'purpose' => 'Setting up and analyzing A/B tests for product features.', 'requirements' => 'A/B testing platform, analytics dashboard', 'content' => '<h2>A/B Testing Process</h2><ol><li>Define the hypothesis and success metrics</li><li>Create test variants</li><li>Configure traffic split and duration</li><li>Launch the test</li><li>Monitor for anomalies during the test</li><li>Analyze results for statistical significance</li><li>Document findings and implement winner</li></ol>'],
            ['title' => 'Managing Print Queue', 'purpose' => 'Resolving issues with the shared office print queue.', 'requirements' => 'Print server access, printer admin panel', 'content' => '<h2>Print Queue Management</h2><ol><li>Check the print queue for stuck jobs</li><li>Clear stuck jobs and restart the spooler</li><li>Verify printer connectivity</li><li>Check for low toner or paper jams</li><li>Test printing from a workstation</li></ol>'],
            ['title' => 'Creating User Documentation', 'purpose' => 'Writing and publishing user-facing documentation.', 'requirements' => 'Documentation platform access, style guide', 'content' => '<h2>Documentation Process</h2><ol><li>Identify the feature or process to document</li><li>Outline the structure and key sections</li><li>Write clear, step-by-step instructions</li><li>Add screenshots and examples</li><li>Review with a peer</li><li>Publish to the documentation site</li></ol>'],
            ['title' => 'Handling Data Breaches', 'purpose' => 'Incident response procedure for suspected data breaches.', 'requirements' => 'Security incident response plan, legal team contact', 'content' => '<h2>Breach Response</h2><ol><li>Contain the breach immediately</li><li>Assess the scope and data affected</li><li>Notify the security team and management</li><li>Engage legal for compliance obligations</li><li>Notify affected parties within required timeframe</li><li>Conduct post-incident review</li><li>Implement preventive measures</li></ol>'],
            ['title' => 'Provisioning Cloud Resources', 'purpose' => 'Requesting and setting up cloud infrastructure resources.', 'requirements' => 'Cloud provider console access, budget approval', 'content' => '<h2>Cloud Provisioning</h2><ol><li>Define resource requirements (compute, storage, networking)</li><li>Estimate costs and get budget approval</li><li>Create resources using IaC templates</li><li>Configure security groups and access</li><li>Tag resources for cost tracking</li><li>Verify with the requesting team</li></ol>'],
            ['title' => 'Conducting Security Audits', 'purpose' => 'Steps for performing internal security audits.', 'requirements' => 'Security scanning tools, compliance checklists', 'content' => '<h2>Security Audit Process</h2><ol><li>Define the audit scope and objectives</li><li>Run automated vulnerability scans</li><li>Review access controls and permissions</li><li>Check compliance with security policies</li><li>Document findings and risk levels</li><li>Create remediation plan</li><li>Schedule follow-up audit</li></ol>'],
            ['title' => 'Managing Office Supplies', 'purpose' => 'Ordering and tracking office supply inventory.', 'requirements' => 'Supply ordering portal, budget allocation', 'content' => '<h2>Supply Management</h2><ol><li>Conduct monthly inventory check</li><li>Identify items below reorder threshold</li><li>Create order in the supply portal</li><li>Get department manager approval</li><li>Track delivery and verify receipt</li><li>Distribute to requesting areas</li></ol>'],
            ['title' => 'Configuring Webhook Integrations', 'purpose' => 'Setting up webhook endpoints for third-party integrations.', 'requirements' => 'API documentation, webhook management panel', 'content' => '<h2>Webhook Setup</h2><ol><li>Identify the event to listen for</li><li>Create the receiving endpoint</li><li>Register the webhook URL in the source application</li><li>Configure event types and payload format</li><li>Test with sample events</li><li>Add error handling and retry logic</li><li>Monitor delivery logs</li></ol>'],
            ['title' => 'Training New Support Agents', 'purpose' => 'Onboarding process for new customer support team members.', 'requirements' => 'Training materials, shadowing schedule, quiz access', 'content' => '<h2>Agent Training</h2><ol><li>Provide product and company overview</li><li>Walk through the support tools and CRM</li><li>Shadow experienced agents for 3 days</li><li>Handle practice tickets with supervision</li><li>Complete the knowledge quiz</li><li>Certify for independent case handling</li></ol>'],
            ['title' => 'Migrating Databases', 'purpose' => 'Moving databases between servers or cloud providers.', 'requirements' => 'Source and target DB access, migration tools', 'content' => '<h2>Database Migration</h2><ol><li>Assess the source database size and schema</li><li>Set up the target database instance</li><li>Test migration with a dry run</li><li>Schedule the migration window</li><li>Execute the migration</li><li>Verify data integrity</li><li>Update application connection strings</li><li>Monitor for issues after cutover</li></ol>'],
            ['title' => 'Managing Feature Flags', 'purpose' => 'Using feature flags to control rollout of new features.', 'requirements' => 'Feature flag management platform', 'content' => '<h2>Feature Flag Process</h2><ol><li>Create the flag with a descriptive name</li><li>Define targeting rules (users, percentages, environments)</li><li>Deploy code gated behind the flag</li><li>Enable gradually (canary → percentage → full)</li><li>Monitor metrics during rollout</li><li>Clean up the flag after full rollout</li></ol>'],
        ];

        // Create 2 folder procedures (they act as parent nodes)
        $folder1 = Procedure::create([
            'identifier' => Procedure::nextIdentifier(),
            'title' => 'IT & Infrastructure',
            'purpose' => 'All procedures related to IT systems, networking, and infrastructure management.',
            'requirements' => null,
            'content' => '<p>This folder contains procedures related to IT and infrastructure operations.</p>',
            'version' => 1,
            'owner_id' => $ownerId,
        ]);
        ProcedureVersion::create([
            'procedure_id' => $folder1->id,
            'version' => 1,
            'title' => $folder1->title,
            'purpose' => $folder1->purpose,
            'requirements' => $folder1->requirements,
            'content' => $folder1->content,
            'change_reason' => 'Initial version',
            'published_by' => $ownerId,
            'published_at' => now(),
        ]);

        $folder2 = Procedure::create([
            'identifier' => Procedure::nextIdentifier(),
            'title' => 'Business Operations',
            'purpose' => 'Procedures for business processes, HR, finance, and customer operations.',
            'requirements' => null,
            'content' => '<p>This folder contains procedures related to day-to-day business operations.</p>',
            'version' => 1,
            'owner_id' => $ownerId,
        ]);
        ProcedureVersion::create([
            'procedure_id' => $folder2->id,
            'version' => 1,
            'title' => $folder2->title,
            'purpose' => $folder2->purpose,
            'requirements' => $folder2->requirements,
            'content' => $folder2->content,
            'change_reason' => 'Initial version',
            'published_by' => $ownerId,
            'published_at' => now(),
        ]);

        $folderIds = [$folder1->id, $folder2->id];

        foreach ($procedures as $i => $data) {
            $proc = Procedure::create([
                'identifier' => Procedure::nextIdentifier(),
                'title' => $data['title'],
                'purpose' => $data['purpose'],
                'requirements' => $data['requirements'],
                'content' => $data['content'],
                'version' => 1,
                'owner_id' => $ownerId,
                'parent_id' => $folderIds[array_rand($folderIds)],
                'sort_order' => $i,
            ]);

            ProcedureVersion::create([
                'procedure_id' => $proc->id,
                'version' => 1,
                'title' => $proc->title,
                'purpose' => $proc->purpose,
                'requirements' => $proc->requirements,
                'content' => $proc->content,
                'change_reason' => 'Initial version',
                'published_by' => $ownerId,
                'published_at' => now(),
            ]);

            // Randomly assign 0-3 tags
            if (! empty($tagIds)) {
                $randomTags = array_slice(
                    $tagIds,
                    0,
                    rand(0, min(3, count($tagIds)))
                );
                shuffle($randomTags);
                if (! empty($randomTags)) {
                    $proc->tags()->sync($randomTags);
                }
            }
        }

        $this->command->info('Created 2 folders and 50 procedures with random folder assignments and tags.');
    }
}
