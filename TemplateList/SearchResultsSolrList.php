<?php
/**
 * @author Mischa Gorinskat <mischa.gorinskat@sourcefabric.org>
 * @copyright 2015 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */
namespace Newscoop\SolrSearchPluginBundle\TemplateList;

use Newscoop\TemplateList\PaginatedBaseList;
use Newscoop\SolrSearchPluginBundle\Search\SolrQuery;

/**
 * SolrSearchPluginBundle List.
 */
class SearchResultsSolrList extends PaginatedBaseList
{
    protected function prepareList($criteria, $parameters)
    {
        $service = \Zend_Registry::get('container')->get('newscoop_solrsearch_plugin.query_service');
        $em = \Zend_Registry::get('container')->get('em');

        $language = $em->getRepository('Newscoop\Entity\Language')
            ->findOneByCode($parameters['language']);
        if (!$language instanceof \Newscoop\Entity\Language) {
            throw new \Exception('"language" parameter for "list_search_results_solr" list is not set! 
                Either set it or configure Solr to store "language_id"');
        }

        $criteria->core = $language->getRFC3066bis();

        try {
            $result = $service->find($this->convertCriteriaToQuery($criteria));
        } catch (\Exception $e) {
            // TODO: Make this work nicely for templates
            throw new \Exception($e->getMessage());
        }

        $docs = array();
        $list = null;
        $helper = \Zend_Registry::get('container')->get('newscoop_solrsearch_plugin.helper');
        $allTypes = $helper->getTypes();
        if (is_array($result) && array_key_exists('response', $result) && array_key_exists('docs', $result['response'])) {
            $userRepo = $em->getRepository('Newscoop\Entity\User');

            $docs = array_map(function ($doc) use ($userRepo, $language, $allTypes) {
                $type = '';
                $object = null;
                switch ($doc['type']) {
                    case 'comment':
                        $type = $doc['type'];
                        $object = new \MetaComment($doc['number']);
                        break;
                    case 'user':
                        $type = $doc['type'];
                        $user = $userRepo->findOneById($doc['number']);
                        if ($user instanceof \Newscoop\Entity\User) {
                            $object = new \MetaUser($user);
                        }
                        break;
                    default:
                        if (in_array($doc['type'], $allTypes)) {
                            $type = 'article';
                            $object = new \MetaArticle($doc['language_id'] ?: $language->getId(), $doc['number']);
                        }

                        break;
                }

                if ($object !== null) {
                    return new SolrResult($type, $object);
                }
            }, $result['response']['docs']);

            $this->setTotalCount($result['response']['numFound']);
            $list = $this->paginateList($docs, null, $criteria->maxResults, null, false);

            // Update the count to the total of returned solr, required to make
            // $gimme->current_list->count work
            $list->count = $result['response']['numFound'];

            return $list;
        }

        return $list;
    }

    /**
     * Convert parameters array to Criteria.
     *
     * @param int   $firstResult
     * @param array $parameters
     *
     * @return Criteria
     */
    protected function convertParameters($firstResult, $parameters)
    {
        if (array_key_exists('rows', $parameters)) {
            $this->criteria->maxResults = $parameters['rows'];
        }
        if (array_key_exists('start', $parameters)) {
            $this->criteria->firstResult = $parameters['start'];
        }

        unset($parameters['rows']);
        unset($parameters['start']);

        foreach ($this->criteria as $key => $value) {
            if (array_key_exists($key, $parameters)) {
                $this->criteria->$key = $parameters[$key];
            }
        }

        parent::convertParameters($firstResult, $parameters);
    }

    /**
     * Converts a SearchResultsSolrCriteria object to a SolrQuer object.
     *
     * @param SearchResultsSolrCriteria $criteria
     *
     * @return SolrQuery
     */
    protected function convertCriteriaToQuery(SearchResultsSolrCriteria $criteria)
    {
        $parameters = (array) $criteria;

        unset($parameters['orderBy']);
        unset($parameters['firstResult']);
        unset($parameters['maxResults']);

        $parameters['sort'] = $criteria->orderBy;
        $parameters['start'] = $criteria->firstResult;
        $parameters['rows'] = $criteria->maxResults;

        return new SolrQuery($parameters);
    }
}
