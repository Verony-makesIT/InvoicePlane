# Feature Request IP-763 INSERT 'CNI{{{id}}}' to ip_invoice_groups table 

INSERT INTO `ip_invoice_groups` (`invoice_group_name`, `invoice_group_identifier_format`, `invoice_group_next_id`, `invoice_group_left_pad`) 
VALUES ('Credit Note/Invoice Default', 'CNI{{{id}}}', 1, 0);
