import torch
import librosa
import numpy as np
from transformers import Wav2Vec2Processor, Wav2Vec2Model
from scipy.spatial.distance import cosine

device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
print("Using device:", device)

processor = Wav2Vec2Processor.from_pretrained("facebook/wav2vec2-base")
model = Wav2Vec2Model.from_pretrained("facebook/wav2vec2-base")
model.to(device)
model.eval()

def extract_embedding(audio, sr=16000):
    inputs = processor(audio, sampling_rate=sr, return_tensors="pt", padding=True)
    input_values = inputs.input_values.to(device)

    with torch.no_grad():
        outputs = model(input_values)

    hidden_states = outputs.last_hidden_state  # (1, time, dim)

    # Mean pooling across time
    embedding = torch.mean(hidden_states, dim=1).squeeze().cpu().numpy()

    return embedding


import os

reference_dir = "reference"
reference_embeddings = {}

for file in os.listdir(reference_dir):
    if file.endswith(".wav"):
        word = file.replace(".wav", "")
        audio, sr = librosa.load(os.path.join(reference_dir, file), sr=16000)

        emb = extract_embedding(audio)
        reference_embeddings[word] = emb

print("Loaded reference words:", list(reference_embeddings.keys()))

import os

reference_dir = "reference"
reference_embeddings = {}

for file in os.listdir(reference_dir):
    if file.endswith(".wav"):
        word = file.replace(".wav", "")
        audio, sr = librosa.load(os.path.join(reference_dir, file), sr=16000)

        emb = extract_embedding(audio)
        reference_embeddings[word] = emb

print("Loaded reference words:", list(reference_embeddings.keys()))

def segment_audio(y, sr):
    hop_length = 512
    rms = librosa.feature.rms(y=y, frame_length=2048, hop_length=hop_length)[0]

    SILENCE_THRESHOLD = 0.02
    MIN_SILENCE_SEC = 0.5

    silent_frames = rms < SILENCE_THRESHOLD
    min_silence_frames = int(MIN_SILENCE_SEC * sr / hop_length)

    segments = []
    start = 0
    i = 0

    while i < len(silent_frames):
        if silent_frames[i]:
            silence_start = i
            while i < len(silent_frames) and silent_frames[i]:
                i += 1
            silence_end = i

            if silence_end - silence_start > min_silence_frames:
                end = silence_start * hop_length
                if end > start:
                    segments.append((start, end))
                start = silence_end * hop_length
        else:
            i += 1

    if start < len(y):
        segments.append((start, len(y)))

    return segments

test_audio, sr = librosa.load("test/input1.wav", sr=16000)
segments = segment_audio(test_audio, sr)

print("Detected segments:", len(segments))


