<?php
// KeywordIntentAnalyzer.php
namespace BlueFission\Framework\Chat;

use BlueFission\Bot\Strategies\NaiveBayesTextClassification;
use BlueFission\Framework\IAnalyzer;
use BlueFission\Framework\Context;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\ModelManager;

class KeywordTopicAnalyzer implements IAnalyzer
{
    private $topicClassifier;

    public function __construct(NaiveBayesTextClassification $topicClassifier)
    {
        $this->topicClassifier = $topicClassifier;
    }

    public function analyze(string $input, Context $topic, array $dialogues): array
    {
        $scores = [];

        // Tokenize the input into words and convert to lowercase
        $inputWords = preg_split('/\s+/', strtolower($input));
        $inputWordCount = count($inputWords);

        $samples = [];
        $labels = [];

        foreach ($dialogues as $topic => $phrases) {
            // Calculate the score for this intent based on keywords, context, and other criteria.
            $score = 0;

            foreach ($phrases as $phrase) {
                $keyword = ['priority'=>$phrase['weight'], 'word'=>$phrase['text']];
                $keywordLower = strtolower($keyword['word']);
                $samples[] = $keyword['word'];
                $labels[] = $topic;
                
                foreach ($inputWords as $inputWord) {
                    $similarityPercent = 0;
                    similar_text($inputWord, $keywordLower, $similarityPercent);

                    // Convert the similarity percentage to a value between 0 and 1
                    $similarityScore = $similarityPercent / 100;

                    // Calculate the multiplier based on the input word count
                    $multiplier = 1 / $inputWordCount;

                    // Calculate the multiplier based on the input word length
                    // $multiplier = strlen($inputWord) / $multiplier;

                    // Increase the score based on the similarity and multiplier
                    $score += $keyword['priority'] * $similarityScore * $multiplier;
                }
            }

            $scores[$topic] = $score;
        }

        $modelFilePath = OPUS_ROOT . '/storage/models/topic_model.phpml';
        $modelManager = new ModelManager();
        if ( file_exists($modelFilePath) ) {
            $loadedPipeline = $modelManager->restoreFromFile($modelFilePath);

            $this->topicClassifier->setPipeline($loadedPipeline);
        } else {
            $this->topicClassifier->train($samples, $labels);

            if ( !file_exists(OPUS_ROOT . '/storage/models') ) {
                mkdir(OPUS_ROOT . '/storage/models');
            }

            $modelManager->saveToFile($this->topicClassifier->getPipeline(), $modelFilePath);
        }

        $classification = $this->topicClassifier->predict($input);
        if ( !isset($scores[$classification]) ) {
            $scores[$classification] = 0;
        }

        $scores[$classification] += 5;

        if ( !empty($scores) ) {
            arsort($scores);
        }

        return $scores;
    }

}
