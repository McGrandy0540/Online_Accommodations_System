<?php
/**
 * Text Analysis Class for Sentiment Analysis and NLP Processing
 */
class TextAnalysis {
    // Sentiment analysis dictionaries
    private $positiveWords = [];
    private $negativeWords = [];
    private $negations = ['not', 'no', 'never', 'none', 'neither', 'nor', 'cannot'];
    private $intensifiers = [
        'very' => 1.5, 'extremely' => 2.0, 'absolutely' => 2.0, 'completely' => 1.8,
        'totally' => 1.8, 'utterly' => 1.8, 'highly' => 1.5, 'really' => 1.3,
        'exceptionally' => 1.7, 'remarkably' => 1.6, 'particularly' => 1.4
    ];
    private $diminishers = [
        'slightly' => 0.8, 'somewhat' => 0.7, 'partially' => 0.6, 'moderately' => 0.7,
        'barely' => 0.5, 'hardly' => 0.5, 'scarcely' => 0.5, 'marginally' => 0.6
    ];

    public function __construct() {
        // Initialize with default word lists
        $this->positiveWords = $this->loadWordList( 'positive_words.txt');
        $this->negativeWords = $this->loadWordList( 'negative_words.txt');
        
        // Fallback word lists if files not found
        if (empty($this->positiveWords)) {
            $this->positiveWords = ['good', 'great', 'excellent', 'wonderful', 'perfect', 'happy', 'nice', 'awesome', 'fantastic', 'amazing'];
        }
        if (empty($this->negativeWords)) {
            $this->negativeWords = ['bad', 'poor', 'terrible', 'horrible', 'awful', 'disappointing', 'unhappy', 'sad', 'angry', 'frustrating'];
        }
    }

    /**
     * Load word list from file
     */
    private function loadWordList($filePath) {
        if (file_exists($filePath)) {
            $words = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_map('trim', $words);
        }
        return [];
    }

    /**
     * Analyze sentiment of a given text
     * Returns array with score (-1 to 1) and label (positive/negative/neutral)
     */
    public function analyzeSentiment($text) {
        // Preprocess text
        $text = $this->preprocessText($text);
        $words = $this->tokenizeText($text);
        
        $sentimentScore = 0;
        $wordCount = count($words);
        
        if ($wordCount === 0) {
            return [
                'score' => 0,
                'label' => 'neutral',
                'words' => []
            ];
        }

        $processedWords = [];
        $negationActive = false;
        $intensifierValue = 1.0;

        foreach ($words as $i => $word) {
            $wordScore = 0;
            $isPositive = false;
            $isNegative = false;

            // Check for negations
            if (in_array($word, $this->negations)) {
                $negationActive = true;
                continue;
            }

            // Check for intensifiers/diminishers
            if (array_key_exists($word, $this->intensifiers)) {
                $intensifierValue = $this->intensifiers[$word];
                continue;
            } elseif (array_key_exists($word, $this->diminishers)) {
                $intensifierValue = $this->diminishers[$word];
                continue;
            }

            // Check positive words
            if (in_array($word, $this->positiveWords)) {
                $wordScore = 1 * $intensifierValue;
                $isPositive = true;
            } 
            // Check negative words
            elseif (in_array($word, $this->negativeWords)) {
                $wordScore = -1 * $intensifierValue;
                $isNegative = true;
            }

            // Apply negation if active
            if ($negationActive && ($isPositive || $isNegative)) {
                $wordScore *= -0.5; // Reverse and diminish the effect
                $negationActive = false;
            }

            // Reset intensifier after each sentiment word
            if ($isPositive || $isNegative) {
                $intensifierValue = 1.0;
            }

            $sentimentScore += $wordScore;

            // Track processed words for debugging/analysis
            $processedWords[] = [
                'word' => $word,
                'score' => $wordScore,
                'negated' => $negationActive,
                'intensifier' => $intensifierValue
            ];
        }

        // Normalize score between -1 and 1
        $normalizedScore = $sentimentScore / $wordCount;
        $normalizedScore = max(-1, min(1, $normalizedScore));

        // Determine sentiment label
        if ($normalizedScore > 0.2) {
            $label = 'positive';
        } elseif ($normalizedScore < -0.2) {
            $label = 'negative';
        } else {
            $label = 'neutral';
        }

        return [
            'score' => $normalizedScore,
            'label' => $label,
            'words' => $processedWords
        ];
    }

    /**
     * Extract keywords from text using TF-IDF approach
     */
    public function extractKeywords($text, $limit = 10) {
        $text = $this->preprocessText($text);
        $words = $this->tokenizeText($text);
        
        // Remove stop words
        $stopWords = $this->getStopWords();
        $words = array_filter($words, function($word) use ($stopWords) {
            return !in_array($word, $stopWords) && strlen($word) > 2;
        });
        
        // Calculate term frequencies
        $termFrequencies = array_count_values($words);
        arsort($termFrequencies);
        
        // Return top keywords
        return array_slice($termFrequencies, 0, $limit, true);
    }

    /**
     * Preprocess text for analysis
     */
    private function preprocessText($text) {
        // Convert to lowercase
        $text = strtolower($text);
        
        // Remove URLs
        $text = preg_replace('@https?://\S+@', '', $text);
        
        // Remove special characters except apostrophes and basic punctuation
        $text = preg_replace("/[^a-zA-Z0-9\s'.,!?]/", '', $text);
        
        return $text;
    }

    /**
     * Tokenize text into words
     */
    private function tokenizeText($text) {
        // Split into words
        $words = preg_split('/\s+/', $text);
        
        // Remove empty elements
        $words = array_filter($words);
        
        // Trim each word
        $words = array_map('trim', $words);
        
        return array_values($words);
    }

    /**
     * Get common stop words
     */
    private function getStopWords() {
        return [
            'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your', 'yours',
            'yourself', 'yourselves', 'he', 'him', 'his', 'himself', 'she', 'her', 'hers',
            'herself', 'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
            'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those', 'am', 'is', 'are',
            'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'having', 'do', 'does',
            'did', 'doing', 'a', 'an', 'the', 'and', 'but', 'if', 'or', 'because', 'as', 'until',
            'while', 'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into',
            'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down',
            'in', 'out', 'on', 'off', 'over', 'under', 'again', 'further', 'then', 'once', 'here',
            'there', 'when', 'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more',
            'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so',
            'than', 'too', 'very', 's', 't', 'can', 'will', 'just', 'don', 'should', 'now'
        ];
    }
}