<?php
namespace App\Business\Preparers;

use BlueFission\Arr;
use Phpml\Tokenization\WhitespaceTokenizer;
use Phpml\Vectorization\Word2Vec;

class SimpleSentenceEmbeddings
{
    private $model;
    private $chunkSize;
    private $overlap;

    public function __construct(string $word2vecModelPath, int $chunkSize = 1, int $overlap = 0)
    {
        $this->model = Word2Vec::load($word2vecModelPath);
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
    }

    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = $chunkSize;
    }

    public function setOverlap(int $overlap): void
    {
        $this->overlap = $overlap;
    }

    public function embedSentence(string $sentence): array
    {
        $tokenizer = new WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize($sentence);
        $wordVectors = Arr::make([]);

        foreach ($tokens as $token) {
            if (isset($this->model->vocabulary[$token])) {
                $wordVectors->push($this->model->getVector($token));
            }
        }

        // The model API and numeric reduction operate on raw vector arrays.
        if ($wordVectors->isEmpty()) {
            return array_fill(0, $this->model->dimensions, 0.0);
        }

        $sentenceVector = array_reduce($wordVectors->val(), function ($carry, $item) {
            foreach ($item as $key => $value) {
                $carry[$key] += $value;
            }
            return $carry;
        }, array_fill(0, $this->model->dimensions, 0.0));

        foreach ($sentenceVector as $key => $value) {
            $sentenceVector[$key] /= $wordVectors->count();
        }

        return $sentenceVector;
    }

    public function embedSentences(array $sentences): array
    {
        $chunkedSentences = $this->chunkSentences($sentences);
        $embeddedChunks = [];

        foreach ($chunkedSentences as $chunk) {
            $embeddedChunks[] = $this->embedSentence(Arr::make($chunk)->join(' ')->val());
        }

        return $embeddedChunks;
    }

    private function chunkSentences(array $sentences): array
    {
        $chunkedSentences = Arr::make([]);
        $sentences = Arr::make($sentences);
        $count = $sentences->count();

        for ($i = 0; $i < $count; $i += $this->chunkSize - $this->overlap) {
            $chunkedSentences->push($sentences->slice($i, $this->chunkSize)->val());
        }

        return $chunkedSentences->val();
    }
}


/*
$word2vecModelPath = 'path/to/word2vec-model.txt';
$sentenceEmbeddings = new SimpleSentenceEmbeddings($word2vecModelPath);

$sentence = "This is an example sentence.";
$sentenceVector = $sentenceEmbeddings->embedSentence($sentence);

print_r($sentenceVector);

 */
