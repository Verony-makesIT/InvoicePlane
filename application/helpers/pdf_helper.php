<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author		InvoicePlane Developers & Contributors
 * @copyright	Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license		https://invoiceplane.com/license.txt
 * @link		https://invoiceplane.com
 *
 * eInvoicing add-ons by Verony
 */

/**
 * Generate the PDF for an invoice
 *
 * @param $invoice_id
 * @param bool $stream
 * @param null $invoice_template
 * @param null $is_guest
 * @return string
 */
function generate_invoice_pdf($invoice_id, $stream = true, $invoice_template = null, $is_guest = null)
{
    $CI = &get_instance();

    $CI->load->model('invoices/mdl_items');
    $CI->load->model('invoices/mdl_invoices');
    $CI->load->model('invoices/mdl_invoice_tax_rates');
    $CI->load->model('custom_fields/mdl_custom_fields');
    $CI->load->model('payment_methods/mdl_payment_methods');

    $CI->load->helper('country');
    $CI->load->helper('client');
    $CI->load->helper('e-invoice');   // eInvoicing++

    $invoice = $CI->mdl_invoices->get_by_id($invoice_id);
    $invoice = $CI->mdl_invoices->get_payments($invoice);

    // Override system language with client language
    set_language($invoice->client_language);

    if (!$invoice_template) {
        $CI->load->helper('template');
        $invoice_template = select_pdf_invoice_template($invoice);
    }

    $payment_method = $CI->mdl_payment_methods->where('payment_method_id', $invoice->payment_method)->get()->row();
    if ($invoice->payment_method == 0) {
        $payment_method = false;
    }

    // Determine if discounts should be displayed
    $items = $CI->mdl_items->where('invoice_id', $invoice_id)->get()->result();

    // Discount settings
    $show_item_discounts = false;
    foreach ($items as $item) {
        if ($item->item_discount != '0.00') {
            $show_item_discounts = true;
        }
    }

    // Get all custom fields
    $custom_fields = array(
        'invoice' => $CI->mdl_custom_fields->get_values_for_fields('mdl_invoice_custom', $invoice->invoice_id),
        'client' => $CI->mdl_custom_fields->get_values_for_fields('mdl_client_custom', $invoice->client_id),
        'user' => $CI->mdl_custom_fields->get_values_for_fields('mdl_user_custom', $invoice->user_id),
    );

    if ($invoice->quote_id) {
        $custom_fields['quote'] = $CI->mdl_custom_fields->get_values_for_fields('mdl_quote_custom', $invoice->quote_id);
    }

    // START eInvoicing++ changes
    $CI->load->helper('settings');
    $file_prefix = trans('invoice');
    $replace = array('.', ' ', '/', '\\', '#');
    if (get_setting('change_filename_prefix') == 1) {
        $user_item = get_setting('add_filename_prefix');
        $file_prefix = str_replace($replace, '', $invoice->$user_item);
    }
    $filename = $file_prefix . '_' . str_replace($replace, '', $invoice->invoice_number);

    // Generate the appropriate UBL/CII
    $xml_id = $invoice->client_einvoice_version;
    $embed_xml = '';
    if (file_exists(APPPATH . 'helpers/XMLconfigs/' . $xml_id . '.php')) {
        include APPPATH . 'helpers/XMLconfigs/' . $xml_id . '.php';

        $embed_xml = $xml_setting['embedXML'];
        $XMLname = $xml_setting['XMLname'];
    }

    // PDF associated or embedded (CII e.g. Zugferd, Factur-X) Xml file
    $associatedFiles = null;
    if ($embed_xml && $invoice->client_einvoicing_active == 1) {
        // Create the CII XML file
        $associatedFiles = array(array(
            'name' => $XMLname,
            'mime' => 'text/xml',
            'description' => $xml_id . ' CII Invoice',
            'AFRelationship' => 'Alternative',
            'path' => generate_xml_invoice_file($invoice, $items, $xml_id, $filename ),
        ));
    } else {
        // Do not embed the XML file if the client e-Invoicing is not active
        $embed_xml = false;
    }

    $data = array(
        'invoice' => $invoice,
        'invoice_tax_rates' => $CI->mdl_invoice_tax_rates->where('invoice_id', $invoice_id)->get()->result(),
        'items' => $items,
        'payment_method' => $payment_method,
        'output_type' => 'pdf',
        'show_item_discounts' => $show_item_discounts,
        'custom_fields' => $custom_fields,
    );

    $html = $CI->load->view('invoice_templates/pdf/' . $invoice_template, $data, true);

    // Create PDF with or without an embedded XML
    $CI->load->helper('mpdf');
    $retval = pdf_create($html, $filename, $stream, $invoice->invoice_password, true, $is_guest, $embed_xml, $associatedFiles);

    if ($embed_xml && file_exists('./uploads/temp/' . $filename . '.xml')) {
        // delete the tmp CII-XML file
        unlink("./uploads/temp/" . $filename . ".xml");
    }

    // Create the UBL XML file
    if ($xml_id != '' && $embed_xml != 'true') {
        // Added the (unnecessary) prefix "date(Y-m-d)_" to the invoice file name to get the same ".pdf" and ".xml" file names!
        if (get_setting('change_filename_prefix') == 0) {
            $filename = date('Y-m-d') . '_' . $filename;
        }

        if ($invoice->client_einvoicing_active == 1) {
            generate_xml_invoice_file($invoice, $items, $xml_id, $filename);
        }
    }
    // END eInvoicing++ changes
    return $retval;

}

function generate_invoice_sumex($invoice_id, $stream = true, $client = false)
{
    $CI = &get_instance();

    $CI->load->model('invoices/mdl_items');
    $invoice = $CI->mdl_invoices->get_by_id($invoice_id);
    $CI->load->library('Sumex', array(
        'invoice' => $invoice,
        'items' => $CI->mdl_items->where('invoice_id', $invoice_id)->get()->result()
    ));

    // Append a copy at the end and change the title:
    // WARNING: The title depends on what invoice type is (TP, TG)
    // and is language-dependant. Fix accordingly if you really need this hack
    $temp = tempnam("/tmp", "invsumex_");
    $tempCopy = tempnam("/tmp", "invsumex_");
    $pdf = new FPDI();
    $sumexPDF = $CI->sumex->pdf();

    $sha1sum = sha1($sumexPDF);
    $shortsum = substr($sha1sum, 0, 8);
    $filename = trans('invoice') . '_' . $invoice->invoice_number . '_' . $shortsum;

    if (!$client) {
        file_put_contents($temp, $sumexPDF);

        // Hackish
        $sumexPDF = str_replace(
            "Giustificativo per la richiesta di rimborso",
            "Copia: Giustificativo per la richiesta di rimborso",
            $sumexPDF
        );

        file_put_contents($tempCopy, $sumexPDF);

        $pageCount = $pdf->setSourceFile($temp);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            if ($size['w'] > $size['h']) {
                $pageFormat = 'L';  //  landscape
            } else {
                $pageFormat = 'P';  //  portrait
            }

            $pdf->addPage($pageFormat, array($size['w'], $size['h']));
            $pdf->useTemplate($templateId);
        }

        $pageCount = $pdf->setSourceFile($tempCopy);

        for ($pageNo = 2; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            if ($size['w'] > $size['h']) {
                $pageFormat = 'L';  //  landscape
            } else {
                $pageFormat = 'P';  //  portrait
            }

            $pdf->addPage($pageFormat, array($size['w'], $size['h']));
            $pdf->useTemplate($templateId);
        }

        unlink($temp);
        unlink($tempCopy);

        if ($stream) {
            header("Content-Type", "application/pdf");
            $pdf->Output($filename . '.pdf', 'I');
            return;
        }

        $filePath = UPLOADS_TEMP_FOLDER . $filename . '.pdf';
        $pdf->Output($filePath, 'F');
        return $filePath;
    } else {
        if ($stream) {
            return $sumexPDF;
        }

        $filePath = UPLOADS_TEMP_FOLDER . $filename . '.pdf';
        file_put_contents($filePath, $sumexPDF);
        return $filePath;
    }
}

/**
 * Generate the PDF for a quote
 *
 * @param $quote_id
 * @param bool $stream
 * @param null $quote_template
 *
 * @return string
 * @throws \Mpdf\MpdfException
 */
function generate_quote_pdf($quote_id, $stream = true, $quote_template = null)
{
    $CI = &get_instance();

    $CI->load->model('quotes/mdl_quotes');
    $CI->load->model('quotes/mdl_quote_items');
    $CI->load->model('quotes/mdl_quote_tax_rates');
    $CI->load->model('custom_fields/mdl_custom_fields');
    $CI->load->helper('country');
    $CI->load->helper('client');

    $quote = $CI->mdl_quotes->get_by_id($quote_id);

    // Override language with system language
    set_language($quote->client_language);

    if (!$quote_template) {
        $quote_template = $CI->mdl_settings->setting('pdf_quote_template');
    }

    // Determine if discounts should be displayed
    $items = $CI->mdl_quote_items->where('quote_id', $quote_id)->get()->result();

    $show_item_discounts = false;
    foreach ($items as $item) {
        if ($item->item_discount != '0.00') {
            $show_item_discounts = true;
        }
    }

    // Get all custom fields
    $custom_fields = array(
        'quote' => $CI->mdl_custom_fields->get_values_for_fields('mdl_quote_custom', $quote->quote_id),
        'client' => $CI->mdl_custom_fields->get_values_for_fields('mdl_client_custom', $quote->client_id),
        'user' => $CI->mdl_custom_fields->get_values_for_fields('mdl_user_custom', $quote->user_id),
    );

    $data = array(
        'quote' => $quote,
        'quote_tax_rates' => $CI->mdl_quote_tax_rates->where('quote_id', $quote_id)->get()->result(),
        'items' => $items,
        'output_type' => 'pdf',
        'show_item_discounts' => $show_item_discounts,
        'custom_fields' => $custom_fields,
    );

    $html = $CI->load->view('quote_templates/pdf/' . $quote_template, $data, true);

    $CI->load->helper('mpdf');

    return pdf_create($html, trans('quote') . '_' . str_replace(array('\\', '/'), '_', $quote->quote_number), $stream, $quote->quote_password);
}
