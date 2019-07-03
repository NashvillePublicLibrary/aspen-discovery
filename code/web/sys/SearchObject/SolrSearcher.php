<?php

require_once ROOT_DIR . '/sys/SearchObject/BaseSearcher.php';

abstract class SearchObject_SolrSearcher extends SearchObject_BaseSearcher
{
    protected $index = null;
    // Field List
    protected $fields = '*,score';
    /** @var Solr */
    protected $indexEngine = null;
    // Result
    protected $indexResult;

    // Facets
    protected $facetLimit = 30;
    protected $facetOffset = null;
    protected $facetPrefix = null;
    protected $facetSort = null;

    // Spelling
    protected $spellcheckEnabled    = true;
    //protected $spellingWordSuggestions   = array();
    protected $spellingLimit = 5;
    protected $spellQuery    = array();
    protected $dictionary    = 'default';

    // Debugging flags
    protected $debug = false;
    protected $debugSolrQuery = false;

    // Publicly viewable version
    protected $publicQuery = null;

    public function __construct(){
        parent::__construct();
        global $configArray;
        // Set appropriate debug mode:
        // Debugging
        if ($configArray['System']['debugSolr']) {
            //Verify that the ip is ok
            global $locationSingleton;
            $activeIp = $locationSingleton->getActiveIp();
            $maintenanceIps = $configArray['System']['maintenanceIps'];
            $debug = true;
            if (strlen($maintenanceIps) > 0){
                $debug = false;
                $allowableIps = explode(',', $maintenanceIps);
                if (in_array($activeIp, $allowableIps)){
                    $debug = true;
                    if ($configArray['System']['debugSolrQuery'] == true) {
                        $this->debugSolrQuery = true;
                    }
                }
            }
            $this->debug = $debug;
        } else {
            $this->debug = false;
        }

        //Setup Spellcheck
        $this->spellcheckEnabled = true;
    }

    function ping(){
    	return $this->indexEngine->pingServer(false);
    }

    /**
     * Load all available facet settings.  This is mainly useful for showing
     * appropriate labels when an existing search has multiple filters associated
     * with it.
     *
     * @access  public
     * @param   string|false   $preferredSection    Section to favor when loading
     *                                              settings; if multiple sections
     *                                              contain the same facet, this
     *                                              section's description will be
     *                                              favored.
     */
    public function activateAllFacets($preferredSection = false)
    {
        foreach($this->allFacetSettings as $section => $values) {
            foreach($values as $key => $value) {
                $this->addFacet($key, $value);
            }
        }

        if ($preferredSection &&
            is_array($this->allFacetSettings[$preferredSection])) {
            foreach($this->allFacetSettings[$preferredSection] as $key => $value) {
                $this->addFacet($key, $value);
            }
        }
    }

    /**
     * Use the record driver to build an array of HTML displays from the search
     * results.
     *
     * @access  public
     * @return  array   Array of HTML chunks for individual records.
     */
    public function getResultRecordHTML()
    {
        global $interface;

        $html = array();
        for ($x = 0; $x < count($this->indexResult['response']['docs']); $x++) {
            $current = & $this->indexResult['response']['docs'][$x];

            $interface->assign('recordIndex', $x + 1);
            $interface->assign('resultIndex', $x + 1 + (($this->page - 1) * $this->limit));
            /** @var IndexRecordDriver $record */
            $record = $this->getRecordDriverForResult($current);
            if (!($record instanceof AspenError)) {
                $interface->assign('recordDriver', $record);
                $html[] = $interface->fetch($record->getSearchResult($this->view));
            } else {
                $html[] = "Unable to find record";
            }
        }
        return $html;
    }

    /**
     * Actually process and submit the search
     *
     * @access  public
     * @param   bool   $returnIndexErrors  Should we die inside the index code if
     *                                     we encounter an error (false) or return
     *                                     it for access via the getIndexError()
     *                                     method (true)?
     * @param   bool   $recommendations    Should we process recommendations along
     *                                     with the search itself?
     * @param   bool   $preventQueryModification   Should we allow the search engine
     *                                             to modify the query or is it already
     *                                             a well formatted query
     * @return  array solr result structure (for now)
     */
    public function processSearch($returnIndexErrors = false, $recommendations = false, $preventQueryModification = false)
    {
        // Our search has already been processed in init()
        $search = $this->searchTerms;

        // Build a recommendations module appropriate to the current search:
        if ($recommendations) {
            $this->initRecommendations();
        }

        // Build Query
        if ($preventQueryModification){
            $query = $search;
        }else{
            $query = $this->indexEngine->buildQuery($search, false);
        }
        if (($query instanceof AspenError)) {
            return $query;
        }

        // Only use the query we just built if there isn't an override in place.
        if ($this->query == null) {
            $this->query = $query;
        }

        // Define Filter Query
        $filterQuery = $this->hiddenFilters;
        //Remove any empty filters if we get them
        //(typically happens when a subdomain has a function disabled that is enabled in the main scope)
        foreach ($this->filterList as $field => $filter) {
            if (empty ($field)){
                unset($this->filterList[$field]);
            }
        }
        foreach ($this->filterList as $field => $filter) {
            foreach ($filter as $value) {
                // Special case -- allow trailing wildcards:
                if (substr($value, -1) == '*') {
                    $filterQuery[] = "$field:$value";
                } elseif (preg_match('/\\A\\[.*?\\sTO\\s.*?]\\z/', $value)){
                    $filterQuery[] = "$field:$value";
                } else {
                    if (!empty($value)){
                        $filterQuery[] = "$field:\"$value\"";
                    }
                }
            }
        }

        // If we are only searching one field use the DisMax handler
        //    for that field. If left at null let solr take care of it
        if (count($search) == 1 && isset($search[0]['index'])) {
            $this->index = $search[0]['index'];
        }

        // Build a list of facets we want from the index
        $facetSet = array();
        if (!empty($this->facetConfig)) {
            $facetSet['limit'] = $this->facetLimit;
            foreach ($this->facetConfig as $facetField => $facetName) {
                $facetSet['field'][] = $facetField;
            }
            if ($this->facetOffset != null) {
                $facetSet['offset'] = $this->facetOffset;
            }
            if ($this->facetPrefix != null) {
                $facetSet['prefix'] = $this->facetPrefix;
            }
            if ($this->facetSort != null) {
                $facetSet['sort'] = $this->facetSort;
            }
        }

        if (!empty($this->facetOptions)){
            $facetSet['additionalOptions'] = $this->facetOptions;
        }

        // Build our spellcheck query
        if ($this->spellcheckEnabled) {
            $spellcheck = $this->buildSpellingQuery();

            // If the spellcheck query is purely numeric, skip it if
            // the appropriate setting is turned on.
            if (is_numeric($spellcheck)) {
                $spellcheck = "";
            }
        } else {
            $spellcheck = "";
        }

        // Get time before the query
        $this->startQueryTimer();

        // The "relevance" sort option is a VuFind reserved word; we need to make
        // this null in order to achieve the desired effect with Solr:
        $finalSort = ($this->sort == 'relevance') ? null : $this->sort;

        // The first record to retrieve:
        //  (page - 1) * limit = start
        $recordStart = ($this->page - 1) * $this->limit;
        $this->indexResult = $this->indexEngine->search(
            $this->query,      // Query string
            $this->index,      // DisMax Handler
            $filterQuery,      // Filter query
            $recordStart,      // Starting record
            $this->limit,      // Records per page
            $facetSet,         // Fields to facet on
            $spellcheck,       // Spellcheck query
            $this->dictionary, // Spellcheck dictionary
            $finalSort,        // Field to sort on
            $this->fields,     // Fields to return
            'POST',     // HTTP Request method
            $returnIndexErrors // Include errors in response?
        );

        // Get time after the query
        $this->stopQueryTimer();

        // How many results were there?
        if (isset($this->indexResult['response']['numFound'])){
            $this->resultsTotal = $this->indexResult['response']['numFound'];
        }else{
            $this->resultsTotal = 0;
        }

        // If extra processing is needed for recommendations, do it now:
        if ($recommendations && is_array($this->recommend)) {
            foreach($this->recommend as $currentSet) {
                foreach($currentSet as $current) {
                    /** @var RecommendationInterface $current */
                    $current->process();
                }
            }
        }

        //Add debug information to the results if available
        if ($this->debug && isset($this->indexResult['debug'])){
            $explainInfo = $this->indexResult['debug']['explain'];
            foreach ($this->indexResult['response']['docs'] as $key => $result){
                if (array_key_exists($result[$this->getUniqueField()], $explainInfo)){
                    $result['explain'] = $explainInfo[$result[$this->getUniqueField()]];
                    $this->indexResult['response']['docs'][$key] = $result;
                }
            }
        }

        // Return the result set
        return $this->indexResult;
    }

    /**
     * Get error message from index response, if any.  This will only work if
     * processSearch was called with $returnIndexErrors set to true!
     *
     * @access  public
     * @return  mixed       false if no error, error string otherwise.
     */
    public function getIndexError()
    {
        return isset($this->indexResult['error']) ?
            $this->indexResult['error'] : false;
    }

    /**
     * Turn the list of spelling suggestions into an array of urls
     *   for on-screen use to implement the suggestions.
     *
     * @access  public
     * @return  array     Spelling suggestion data arrays
     */
    public function getSpellingSuggestions()
    {
        $returnArray = array();

        $correctlySpelled = isset($this->indexResult['spellcheck']) ? $this->indexResult['spellcheck']['correctlySpelled'] : true;
        $spellingCollations = isset($this->indexResult['spellcheck']['collations']) ? $this->indexResult['spellcheck']['collations'] : array();
        if (count($spellingCollations) > 0) {
	        foreach ($spellingCollations as $collation) {
		        if ($collation[0] == 'collation') {
			        $label = $collation[1]['collationQuery'];
			        $freq = $collation[1]['hits'];
			        $oldTerms = [];
			        $newTerms = [];
			        $okToUseSuggestion = true;
			        foreach ($collation[1]['misspellingsAndCorrections'] as $replacements) {
				        $oldTerms[] = $replacements[0];
				        $newTerms[] = $replacements[1];
				        //Solr sometimes just
				        if (strpos($replacements[1], ' ') > 0){
				        	$replacementWords = explode(' ', $replacements[1]);
				        	foreach ($replacementWords as $word){
				        		if (strlen($word) == 1){
				        			$okToUseSuggestion = false;
						        }
					        }
				        }
			        }
			        if ($okToUseSuggestion){
				        $returnArray[sprintf('%08d', $freq) . $label] = array(
					        'freq' => $freq,
					        'replace_url' => $this->renderLinkWithReplacedTerm($oldTerms, $newTerms),
					        'phrase' => $label
				        );
			        }
		        }
	        }
        }

	    //Sort the collations based to get the result that has the most docs in it.
		krsort($returnArray);

        return [
            'correctlySpelled' => $correctlySpelled,
            'suggestions' => $returnArray
        ];
    }

    /**
     * Adapt the search query to a spelling query
     *
     * @access  protected
     * @return  string    Spelling query
     */
    protected function buildSpellingQuery()
    {
        $this->spellQuery = array();
        // Basic search
        if ($this->searchType == $this->basicSearchType) {
            // Just the search query is fine
            return $this->query;

            // Advanced search
        } else {
            foreach ($this->searchTerms as $search) {
                foreach ($search['group'] as $field) {
                    // Add just the search terms to the list
                    $this->spellQuery[] = $field['lookfor'];
                }
            }
            // Return the list put together as a string
            return join(" ", $this->spellQuery);
        }
    }

    public function getUniqueField(){
        return 'id';
    }

    public abstract function getRecordDriverForResult($record);

    /**
     * Process facets from the results object
     *
     * @access  public
     * @param   array   $filter         Array of field => on-screen description
     *                                  listing all of the desired facet fields;
     *                                  set to null to get all configured values.
     * @param   bool    $expandingLinks If true, we will include expanding URLs
     *                                  (i.e. get all matches for a facet, not
     *                                  just a limit to the current search) in
     *                                  the return array.
     * @return  array   Facets data arrays
     */
    public function getFacetList($filter = null, $expandingLinks = false)
    {
        // If there is no filter, we'll use all facets as the filter:
        if (is_null($filter)) {
            $filter = $this->facetConfig;
        }

        // Start building the facet list:
        $list = array();

        // If we have no facets to process, give up now
        if (!isset($this->indexResult['facet_counts'])){
            return $list;
        }elseif (empty($this->indexResult['facet_counts']['facet_fields']) && empty($this->indexResult['facet_counts']['facet_dates'])) {
            return $list;
        }

        // Loop through every field returned by the result set
        $validFields = array_keys($filter);

        if (isset($this->indexResult['facet_counts']['facet_dates'])){
            $allFacets = array_merge($this->indexResult['facet_counts']['facet_fields'], $this->indexResult['facet_counts']['facet_dates']);
        }else{
            $allFacets = $this->indexResult['facet_counts']['facet_fields'];
        }

        foreach ($allFacets as $field => $data) {
            // Skip filtered fields and empty arrays:
            if (!in_array($field, $validFields) || count($data) < 1) {
                continue;
            }

            // Initialize the settings for the current field
            $list[$field] = array();
            // Add the on-screen label
            $list[$field]['label'] = $filter[$field];
            // Build our array of values for this field
            $list[$field]['list']  = array();

            // Should we translate values for the current facet?
            $translate = in_array($field, $this->translatedFacets);

            // Loop through values:
            foreach ($data as $facet) {
                // Initialize the array of data about the current facet:
                $currentSettings = array();
                $currentSettings['value'] = $facet[0];
                $currentSettings['display'] = $translate ? translate($facet[0]) : $facet[0];
                $currentSettings['count'] = $facet[1];
                $currentSettings['isApplied'] = false;
                $currentSettings['url'] = $this->renderLinkWithFilter("$field:".$facet[0]);
                // If we want to have expanding links (all values matching the facet)
                // in addition to limiting links (filter current search with facet),
                // do some extra work:
                if ($expandingLinks) {
                    $currentSettings['expandUrl'] = $this->getExpandingFacetLink($field, $facet[0]);
                }

                // Is this field a current filter?
                if (in_array($field, array_keys($this->filterList))) {
                    // and is this value a selected filter?
                    if (in_array($facet[0], $this->filterList[$field])) {
                        $currentSettings['isApplied'] = true;
                        $currentSettings['removalUrl'] =  $this->renderLinkWithoutFilter("$field:{$facet[0]}");
                    }
                }

                //Setup the key to allow sorting alphabetically if needed.
                $valueKey = $facet[0];

                // Store the collected values:
                $list[$field]['list'][$valueKey] = $currentSettings;
            }

            //How many facets should be shown by default
            $list[$field]['valuesToShow'] = 5;

            //Sort the facet alphabetically?
            //Sort the system and location alphabetically unless we are in the global scope
            $list[$field]['showAlphabetically'] = false;
            if ($list[$field]['showAlphabetically']){
                ksort($list[$field]['list']);
            }
        }
        return $list;
    }

    public function disableSpelling(){
        $this->spellcheckEnabled = false;
    }

    public function enableSpelling(){
        $this->spellcheckEnabled = true;
    }

    /**
     * Return the record set from the search results.
     *
     * @access  public
     * @return  array   recordSet
     */
    public function getResultRecordSet()
    {
        //Marmot add shortIds without dot for use in display.
        if (isset($this->indexResult['response'])){
            $recordSet = $this->indexResult['response']['docs'];
            if (is_array($recordSet)){
                foreach ($recordSet as $key => $record){
                    //Trim off the dot from the start
                    $record['shortId'] = substr($record['id'], 1);
                    if (!$this->debug){
                        unset($record['explain']);
                        unset($record['score']);
                    }
                    $recordSet[$key] = $record;
                }
            }
        }else{
            return array();
        }

        return $recordSet;
    }

    /**
     * Turn our results into an RSS feed
     *
     * @access  public
     * @param  array|null   $result      Existing result set (null to do new search)
     * @return  string                  XML document
     */
    public function buildRSS($result = null)
    {
        global $configArray;
        // XML HTTP header
        header('Content-type: text/xml', true);

        // First, get the search results if none were provided
        // (we'll go for 50 at a time)
        if (is_null($result)) {
            $this->limit = 50;
            $result = $this->processSearch(false, false);
        }

        for ($i = 0; $i < count($result['response']['docs']); $i++) {
            $current = & $this->indexResult['response']['docs'][$i];

            /** @var IndexRecordDriver $record */
            $record = RecordDriverFactory::initRecordDriver($current);
            if (!($record instanceof AspenError)) {
                $result['response']['docs'][$i]['recordUrl'] = $record->getAbsoluteUrl();
                $result['response']['docs'][$i]['title_display'] = $record->getTitle();
                $image = $record->getBookcoverUrl('medium');
                $description = "<img src='$image' alt='cover image'/> ";
                $result['response']['docs'][$i]['rss_description'] = $description;
            } else {
                $html[] = "Unable to find record";
            }
        }

        global $interface;

        // On-screen display value for our search
        $lookfor = $this->displayQuery();

        if (count($this->filterList) > 0) {
            // TODO : better display of filters
            $interface->assign('lookfor', $lookfor . " (" . translate('with filters') . ")");
        } else {
            $interface->assign('lookfor', $lookfor);
        }
        // The full url to recreate this search
        $interface->assign('searchUrl', $configArray['Site']['url']. $this->renderSearchUrl());
        // Stub of a url for a records screen
        $interface->assign('baseUrl',    $configArray['Site']['url']);

        $interface->assign('result', $result);
        return $interface->fetch('Search/rss.tpl');
    }

    /**
     * Build a string for onscreen display showing the
     *   query used in the search (not the filters).
     *
     * @access  public
     * @param bool $forceRebuild
     * @return  string   user friendly version of 'query'
     */
    public function displayQuery($forceRebuild = false)
    {
        // Maybe this is a restored object...
        if ($this->query == null || $forceRebuild) {
            $fullQuery = $this->indexEngine->buildQuery($this->searchTerms, false);
            $displayQuery = $this->indexEngine->buildQuery($this->searchTerms, true);
            $this->query = $fullQuery;
            if ($fullQuery != $displayQuery){
                $this->publicQuery = $displayQuery;
            }
        }

        // Do we need the complex answer? Advanced searches
        if ($this->searchType == $this->advancedSearchType) {
            $output = $this->buildAdvancedDisplayQuery();
            // If there is a hardcoded public query (like tags) return that
        } else if ($this->publicQuery != null) {
            $output = $this->publicQuery;
            // If we don't already have a public query, and this is a basic search
            // with case-insensitive booleans, we need to do some extra work to ensure
            // that we display the user's query back to them unmodified (i.e. without
            // capitalized Boolean operators)!
        } else if (!$this->indexEngine->hasCaseSensitiveBooleans()) {
            $output = $this->publicQuery = $this->indexEngine->buildQuery($this->searchTerms, true);
            // Simple answer
        } else {
            $output = $this->query;
        }

        // Empty searches will look odd to users
        if ($output == '*:*') {
            $output = "";
        }

        return $output;
    }

    /**
     * Used during repeated deminification (such as search history).
     *   To scrub fields populated above.
     *
     * @access  private
     */
    protected function purge()
    {
        // Call standard purge:
        parent::purge();

        // Make some Solr-specific adjustments:
        $this->query        = null;
        $this->publicQuery  = null;
    }

    public function getIndexEngine()    {return $this->indexEngine;}

    protected function processSearchSuggestions(string $searchTerm, string $suggestionHandler)
    {
        $suggestions = $this->indexEngine->getSearchSuggestions($searchTerm, $suggestionHandler);
        $allSuggestions = [];
        if (isset($suggestions['suggest'])){
            foreach ($suggestions['suggest'] as $suggestionType => $suggestedSearchesByType){
                foreach ($suggestedSearchesByType as $term => $suggestionsForTerm) {
                    foreach ($suggestionsForTerm['suggestions'] as $index => $suggestion) {
                        $nonHighlightedTerm = preg_replace('~</?b>~', '', $suggestion['term']);
                        if (strcasecmp($nonHighlightedTerm, $searchTerm) === 0){
                        	continue;
                        }
                        //Remove the old value if this is a duplicate (after incrementing the weight)
                        foreach ($allSuggestions as $key => $value) {
                            if ($value['nonHighlightedTerm'] == $nonHighlightedTerm) {
                                $suggestion['weight'] += $value['numSearches'];
                                unset($allSuggestions[$key]);
                                break;
                            }
                        }
                        $allSuggestions[str_pad(($suggestion['weight'] + count($suggestionsForTerm['suggestions']) - $index), 10, '0', STR_PAD_LEFT) . $nonHighlightedTerm] = array('phrase'=>$suggestion['term'], 'numSearches'=>$suggestion['weight'], 'numResults'=>$suggestion['weight'], 'nonHighlightedTerm' => $nonHighlightedTerm);
                    }
                }
            }
        }

        krsort($allSuggestions);
        if (count ($allSuggestions) > 8){
            $allSuggestions = array_slice($allSuggestions, 0, 8);
        }
        return $allSuggestions;
    }
}