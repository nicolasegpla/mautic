<?php

namespace Mautic\LeadBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TimelineController extends CommonController
{
    use LeadAccessTrait;
    use LeadDetailsTrait;

    public function indexAction(Request $request, $leadId, $page = 1)
    {
        if (empty($leadId)) {
            return $this->accessDenied();
        }

        $lead = $this->checkLeadAccess($leadId, 'view');
        if ($lead instanceof Response) {
            return $lead;
        }

        $this->setListFilters();

        $session = $request->getSession();
        if ('POST' == $request->getMethod() && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents') ?? []),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents') ?? []),
            ];
            $session->set('mautic.lead.'.$leadId.'.timeline.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.lead.'.$leadId.'.timeline.orderby'),
            $session->get('mautic.lead.'.$leadId.'.timeline.orderbydir'),
        ];

        $events = $this->getEngagements($lead, $filters, $order, $page);

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lead'   => $lead,
                    'page'   => $page,
                    'events' => $events,
                ],
                'passthroughVars' => [
                    'route'         => false,
                    'mauticContent' => 'leadTimeline',
                    'timelineCount' => $events['total'],
                ],
                'contentTemplate' => '@MauticLead/Timeline/_list.html.twig',
            ]
        );
    }

    public function pluginIndexAction(Request $request, $integration, $page = 1)
    {
        $limit = 25;
        $leads = $this->checkAllAccess('view', $limit);

        if ($leads instanceof Response) {
            return $leads;
        }

        $this->setListFilters();

        $session = $request->getSession();
        if ('POST' === $request->getMethod() && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents') ?? []),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents') ?? []),
            ];
            $session->set('mautic.plugin.timeline.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.plugin.timeline.orderby'),
            $session->get('mautic.plugin.timeline.orderbydir'),
        ];

        // get all events grouped by lead
        $events = $this->getAllEngagements($leads, $filters, $order, $page, $limit);

        $str = $request->server->get('QUERY_STRING');
        parse_str($str, $query);

        $tmpl = 'table';
        if (array_key_exists('from', $query) && 'iframe' === $query['from']) {
            $tmpl = 'list';
        }
        if (array_key_exists('tmpl', $query)) {
            $tmpl = $query['tmpl'];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'leads'       => $leads,
                    'page'        => $page,
                    'events'      => $events,
                    'integration' => $integration,
                    'tmpl'        => (!$request->isXmlHttpRequest()) ? 'index' : '',
                    'newCount'    => (array_key_exists('count', $query) && $query['count']) ? $query['count'] : 0,
                ],
                'passthroughVars' => [
                    'route'         => false,
                    'mauticContent' => 'pluginTimeline',
                    'timelineCount' => $events['total'],
                ],
                'contentTemplate' => sprintf('@MauticLead/Timeline/plugin_%s.html.twig', $tmpl),
            ]
        );
    }

    public function pluginViewAction(Request $request, $integration, $leadId, $page = 1)
    {
        if (empty($leadId)) {
            return $this->notFound();
        }

        $lead = $this->checkLeadAccess($leadId, 'view', true, $integration);
        if ($lead instanceof Response) {
            return $lead;
        }

        $this->setListFilters();

        $session = $request->getSession();
        if ('POST' === $request->getMethod() && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents') ?? []),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents') ?? []),
            ];
            $session->set('mautic.plugin.timeline.'.$leadId.'.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.plugin.timeline.'.$leadId.'.orderby'),
            $session->get('mautic.plugin.timeline.'.$leadId.'.orderbydir'),
        ];

        $events = $this->getEngagements($lead, $filters, $order, $page);

        $str = $request->server->get('QUERY_STRING');
        parse_str($str, $query);

        $tmpl = 'table';
        if (array_key_exists('from', $query) && 'iframe' === $query['from']) {
            $tmpl = 'list';
        }
        if (array_key_exists('tmpl', $query)) {
            $tmpl = $query['tmpl'];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lead'        => $lead,
                    'page'        => $page,
                    'integration' => $integration,
                    'events'      => $events,
                    'newCount'    => (array_key_exists('count', $query) && $query['count']) ? $query['count'] : 0,
                ],
                'passthroughVars' => [
                    'route'         => false,
                    'mauticContent' => 'pluginTimeline',
                    'timelineCount' => $events['total'],
                ],
                'contentTemplate' => sprintf('@MauticLead/Timeline/plugin_%s.html.twig', $tmpl),
            ]
        );
    }

    public function batchExportAction(Request $request, DateHelper $dateHelper, ExportHelper $exportHelper, $leadId): array|Response
    {
        if (empty($leadId)) {
            return $this->accessDenied();
        }

        $lead = $this->checkLeadAccess($leadId, 'view');
        if ($lead instanceof Response) {
            return $lead;
        }

        if (!$this->security->isGranted('report:export:enable', 'MATCH_ONE')) {
            return $this->accessDenied();
        }

        $this->setListFilters();

        $session = $request->getSession();
        if ('POST' == $request->getMethod() && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents') ?? []),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents') ?? []),
            ];
            $session->set('mautic.lead.'.$leadId.'.timeline.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.lead.'.$leadId.'.timeline.orderby'),
            $session->get('mautic.lead.'.$leadId.'.timeline.orderbydir'),
        ];

        $dataType = $request->get('filetype', 'csv');

        $resultsCallback = function ($event) use ($dateHelper): array {
            $eventLabel = $event['eventLabel'] ?? $event['eventType'];
            if (is_array($eventLabel)) {
                $eventLabel = $eventLabel['label'];
            }

            return [
                'eventName'      => $eventLabel,
                'eventType'      => $event['eventType'] ?? '',
                'eventTimestamp' => $dateHelper->toText($event['timestamp'], 'local', 'Y-m-d H:i:s', true),
            ];
        };

        $results    = $this->getEngagements($lead, $filters, $order, 1, 200);
        $count      = $results['total'];
        $items      = $results['events'];
        $iterations = ceil($count / 200);
        $loop       = 1;

        // Max of 50 iterations for 10K result export
        if ($iterations > 50) {
            $iterations = 50;
        }

        $toExport = [];

        while ($loop <= $iterations) {
            if (is_callable($resultsCallback)) {
                foreach ($items as $item) {
                    $toExport[] = $resultsCallback($item);
                }
            } else {
                foreach ($items as $item) {
                    $toExport[] = (array) $item;
                }
            }

            $items = $this->getEngagements($lead, $filters, $order, $loop + 1, 200);

            $this->doctrine->getManager()->clear();

            ++$loop;
        }

        return $this->exportResultsAs($toExport, $dataType, 'contact_timeline', $exportHelper);
    }
}
