"""
Speech Pronunciation Assessment - Phoneme-level Analysis
Analyzes spoken words and scores individual phonemes to identify pronunciation errors.
"""

import torch
import librosa
import numpy as np
import matplotlib
matplotlib.use('Agg')  # Use non-interactive backend (no pop-up windows)
import matplotlib.pyplot as plt
import os
import sys
import json
from transformers import Wav2Vec2Processor, Wav2Vec2Model
from scipy.spatial.distance import cosine
from datetime import datetime


# ============================================================================
# SETUP & MODEL LOADING
# ============================================================================

def setup_model():
    """Initialize Wav2Vec2 model and processor."""
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print("Using device:", device)

    processor = Wav2Vec2Processor.from_pretrained("facebook/wav2vec2-base")
    model = Wav2Vec2Model.from_pretrained("facebook/wav2vec2-base")
    model.to(device)
    model.eval()
    
    return model, processor, device


# ============================================================================
# EMBEDDING EXTRACTION
# ============================================================================

def extract_embedding(audio, processor, model, device, sr=16000):
    """Extract mean-pooled embedding from audio."""
    inputs = processor(audio, sampling_rate=sr, return_tensors="pt", padding=True)
    input_values = inputs.input_values.to(device)

    with torch.no_grad():
        outputs = model(input_values)

    hidden_states = outputs.last_hidden_state  # (1, time, dim)
    embedding = torch.mean(hidden_states, dim=1).squeeze().cpu().numpy()

    return embedding


def extract_frame_embeddings(audio, processor, model, device, sr=16000):
    """Extract frame-level embeddings (time dimension preserved)."""
    inputs = processor(audio, sampling_rate=sr, return_tensors="pt", padding=True)
    input_values = inputs.input_values.to(device)

    with torch.no_grad():
        outputs = model(input_values)

    return outputs.last_hidden_state.squeeze(0).cpu().numpy()  # (time, dim)


# ============================================================================
# AUDIO SEGMENTATION
# ============================================================================

def segment_audio(y, sr):
    """
    Segment audio into words based on silence detection.
    """
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


# ============================================================================
# REFERENCE WORD LOADING
# ============================================================================

def load_reference_words(reference_dir, processor, model, device):
    """Load and process all reference word pronunciations."""
    reference_embeddings = {}

    if not os.path.exists(reference_dir):
        print(f"Warning: Reference directory '{reference_dir}' not found!")
        return reference_embeddings

    for file in os.listdir(reference_dir):
        if file.endswith(".wav"):
            word = file.replace(".wav", "")
            audio, sr = librosa.load(os.path.join(reference_dir, file), sr=16000)

            emb = extract_embedding(audio, processor, model, device)
            reference_embeddings[word] = emb

    print("Loaded reference words:", list(reference_embeddings.keys()))
    return reference_embeddings


# ============================================================================
# PHONEME PROCESSING
# ============================================================================

# Phoneme dictionary (ARPABET notation)
PHONEME_DICT = {
    "shy": ["SH", "AY"],
    "sheep": ["SH", "IY", "P"],
    "shake": ["SH", "EY", "K"],
    "short": ["SH", "AO", "R", "T"],
    "sharp": ["SH", "AA", "R", "P"],
    "shiny": ["SH", "AY", "N", "IY"],
    "shouting": ["SH", "AW", "T", "IH", "NG"],
    "shivering": ["SH", "IH", "V", "ER", "IH", "NG"],
    "shockingly": ["SH", "AA", "K", "IH", "NG", "L", "IY"]
}


def slice_phoneme_windows(frame_embs, phonemes):
    """
    Divide frame embeddings into phoneme windows.
    (Simple equal division - not linguistically accurate)
    """
    n_frames = len(frame_embs)
    n_ph = len(phonemes)

    window_size = n_frames // n_ph
    phoneme_windows = {}

    for i, ph in enumerate(phonemes):
        start = i * window_size
        end = (i + 1) * window_size if i < n_ph - 1 else n_frames

        phoneme_windows[ph] = frame_embs[start:end]

    return phoneme_windows


def build_phoneme_prototypes(reference_dir, phoneme_dict, processor, model, device):
    """
    Build prototype embeddings for each phoneme from reference words.
    """
    phoneme_prototypes = {}

    for word, phonemes in phoneme_dict.items():
        ref_path = os.path.join(reference_dir, f"{word}.wav")
        
        if not os.path.exists(ref_path):
            print(f"Warning: Reference file not found for '{word}'")
            continue
            
        ref_audio, _ = librosa.load(ref_path, sr=16000)
        frame_embs = extract_frame_embeddings(ref_audio, processor, model, device)

        windows = slice_phoneme_windows(frame_embs, phonemes)

        for ph, emb_window in windows.items():
            proto = np.mean(emb_window, axis=0)

            if ph not in phoneme_prototypes:
                phoneme_prototypes[ph] = []

            phoneme_prototypes[ph].append(proto)

    # Average prototypes across all occurrences
    for ph in phoneme_prototypes:
        phoneme_prototypes[ph] = np.mean(phoneme_prototypes[ph], axis=0)

    print(f"Built prototypes for {len(phoneme_prototypes)} unique phonemes")
    return phoneme_prototypes


# ============================================================================
# PHONEME ANALYSIS
# ============================================================================

def analyze_word(word_audio, expected_phonemes, phoneme_prototypes, processor, model, device):
    """
    Analyze a spoken word and score each phoneme.
    """
    frame_embs = extract_frame_embeddings(word_audio, processor, model, device)
    windows = slice_phoneme_windows(frame_embs, expected_phonemes)

    scores = {}

    for ph, emb_window in windows.items():
        test_vec = np.mean(emb_window, axis=0)
        ref_vec = phoneme_prototypes.get(ph)

        if ref_vec is None:
            print(f"Warning: No prototype found for phoneme '{ph}'")
            continue

        similarity = 1 - cosine(test_vec, ref_vec)
        scores[ph] = similarity

    return scores


def classify_phoneme_errors(scores, threshold=0.7):
    """
    Classify phoneme pronunciation quality.
    """
    results = {}

    for ph, sim in scores.items():
        if sim >= threshold:
            results[ph] = "OK"
        elif sim >= 0.5:
            results[ph] = "Weak"
        else:
            results[ph] = "Likely Error"

    return results


# ============================================================================
# VISUALIZATION - SAVES TO FILE
# ============================================================================

def plot_heatmap(scores, word_label, output_dir="output"):
    """
    Visualize phoneme similarity scores as a heatmap and save to file.
    """
    # Create output directory if it doesn't exist
    os.makedirs(output_dir, exist_ok=True)
    
    phonemes = list(scores.keys())
    values = [scores[p] for p in phonemes]

    plt.figure(figsize=(12, 3))
    plt.imshow([values], aspect="auto", cmap="RdYlGn", vmin=0, vmax=1)
    plt.yticks([])
    plt.xticks(range(len(phonemes)), phonemes, fontsize=12, fontweight='bold')
    plt.colorbar(label="Similarity Score", shrink=0.8)
    plt.title(f"Phoneme Similarity Analysis – {word_label}", fontsize=14, fontweight='bold')
    
    # Add value labels on each cell
    for i, (ph, val) in enumerate(zip(phonemes, values)):
        color = 'white' if val < 0.5 else 'black'
        plt.text(i, 0, f'{val:.2f}', ha='center', va='center', 
                color=color, fontsize=10, fontweight='bold')
    
    plt.tight_layout()
    
    # Generate filename with timestamp
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"{word_label}_heatmap_{timestamp}.png"
    filepath = os.path.join(output_dir, filename)
    
    # Save the figure
    plt.savefig(filepath, dpi=300, bbox_inches='tight')
    plt.close()  # Close figure to free memory
    
    print(f"✓ Heatmap saved: {filepath}")
    return filepath


# ============================================================================
# WORD MATCHING
# ============================================================================

def match_segments_to_words(test_audio, segments, reference_embeddings, processor, model, device):
    """
    Match audio segments to reference words.
    """
    results = []
    
    for idx, (s, e) in enumerate(segments):
        word_audio = test_audio[s:e]

        if len(word_audio) < 400:
            continue

        test_emb = extract_embedding(word_audio, processor, model, device)

        similarities = {}
        for ref_word, ref_emb in reference_embeddings.items():
            sim = 1 - cosine(test_emb, ref_emb)
            similarities[ref_word] = sim

        best_match = max(similarities, key=similarities.get)
        
        results.append({
            'segment': idx + 1,
            'audio': word_audio,
            'best_match': best_match,
            'similarity': similarities[best_match]
        })

        print(f"\nSegment {idx+1}")
        print("Best match:", best_match)
        print("Similarity:", round(similarities[best_match], 3))
    
    return results


# ============================================================================
# GENERATE TEXT REPORT
# ============================================================================

def save_text_report(word, scores, errors, output_dir="output"):
    """
    Save detailed text report of the analysis.
    """
    os.makedirs(output_dir, exist_ok=True)
    
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"{word}_report_{timestamp}.txt"
    filepath = os.path.join(output_dir, filename)
    
    with open(filepath, 'w') as f:
        f.write("="*70 + "\n")
        f.write(f"SPEECH PRONUNCIATION ASSESSMENT REPORT\n")
        f.write(f"Word: {word.upper()}\n")
        f.write(f"Analysis Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write("="*70 + "\n\n")
        
        f.write("PHONEME SCORES:\n")
        f.write("-"*70 + "\n")
        f.write(f"{'Phoneme':<15} {'Score':<15} {'Status':<20}\n")
        f.write("-"*70 + "\n")
        
        for ph in scores:
            score = scores[ph]
            status = errors[ph]
            emoji = "✓" if status == "OK" else "⚠" if status == "Weak" else "✗"
            f.write(f"{ph:<15} {score:<15.3f} {emoji} {status:<20}\n")
        
        f.write("\n" + "="*70 + "\n")
        f.write("SUMMARY:\n")
        f.write("-"*70 + "\n")
        
        total = len(scores)
        ok_count = sum(1 for status in errors.values() if status == "OK")
        weak_count = sum(1 for status in errors.values() if status == "Weak")
        error_count = sum(1 for status in errors.values() if status == "Likely Error")
        
        f.write(f"Total Phonemes: {total}\n")
        f.write(f"OK: {ok_count} ({ok_count/total*100:.1f}%)\n")
        f.write(f"Weak: {weak_count} ({weak_count/total*100:.1f}%)\n")
        f.write(f"Errors: {error_count} ({error_count/total*100:.1f}%)\n")
        f.write(f"Overall Score: {sum(scores.values())/total*100:.1f}%\n")
        f.write("="*70 + "\n")
    
    print(f"✓ Text report saved: {filepath}")
    return filepath


# ============================================================================
# SAVE JSON OUTPUT FOR PHP
# ============================================================================

def save_json_output(word, scores, errors, heatmap_path, report_path, output_dir="output"):
    """
    Save analysis results as JSON for easy PHP consumption.
    """
    os.makedirs(output_dir, exist_ok=True)
    
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"{word}_result_{timestamp}.json"
    filepath = os.path.join(output_dir, filename)
    
    # Calculate summary statistics
    total = len(scores)
    ok_count = sum(1 for status in errors.values() if status == "OK")
    weak_count = sum(1 for status in errors.values() if status == "Weak")
    error_count = sum(1 for status in errors.values() if status == "Likely Error")
    overall_score = sum(scores.values()) / total * 100 if total > 0 else 0
    
    result = {
        "status": "success",
        "word": word,
        "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        "phoneme_scores": scores,
        "phoneme_errors": errors,
        "summary": {
            "total_phonemes": total,
            "ok_count": ok_count,
            "weak_count": weak_count,
            "error_count": error_count,
            "overall_score": round(overall_score, 2)
        },
        "files": {
            "heatmap": os.path.abspath(heatmap_path),
            "report": os.path.abspath(report_path),
            "json": os.path.abspath(filepath)
        }
    }
    
    with open(filepath, 'w') as f:
        json.dump(result, f, indent=2)
    
    print(f"✓ JSON result saved: {filepath}")
    return filepath, result


# ============================================================================
# MAIN EXECUTION
# ============================================================================

def main():
    """
    Main workflow for speech pronunciation assessment.
    Accepts command line arguments from PHP.
    
    Usage:
        python speech_assessment.py <reference_dir> <test_audio_path> <test_word> <output_dir>
    
    Example:
        python speech_assessment.py "reference" "test/input1.wav" "shockingly" "output"
    """
    
    # Default values
    REFERENCE_DIR = "reference"
    TEST_AUDIO_PATH = "test/input1.wav"
    TEST_WORD = "shockingly"
    OUTPUT_DIR = "output"
    
    # Parse command line arguments
    if len(sys.argv) >= 5:
        REFERENCE_DIR = sys.argv[1]
        TEST_AUDIO_PATH = sys.argv[2]
        TEST_WORD = sys.argv[3]
        OUTPUT_DIR = sys.argv[4]
        print(f"Arguments received from PHP:")
        print(f"  Reference Dir: {REFERENCE_DIR}")
        print(f"  Test Audio: {TEST_AUDIO_PATH}")
        print(f"  Test Word: {TEST_WORD}")
        print(f"  Output Dir: {OUTPUT_DIR}")
    elif len(sys.argv) > 1:
        print("Error: Insufficient arguments!")
        print("Usage: python speech_assessment.py <reference_dir> <test_audio_path> <test_word> <output_dir>")
        sys.exit(1)
    else:
        print("Using default configuration (no arguments provided)")
    
    print("="*70)
    print("Speech Pronunciation Assessment - Phoneme-level Analysis")
    print("="*70)
    
    try:
        # Step 1: Setup model
        print("\n[1/7] Loading model...")
        model, processor, device = setup_model()
        
        # Step 2: Load reference words
        print("\n[2/7] Loading reference pronunciations...")
        reference_embeddings = load_reference_words(REFERENCE_DIR, processor, model, device)
        
        if not reference_embeddings:
            raise Exception(f"No reference files found in '{REFERENCE_DIR}'")
        
        # Step 3: Build phoneme prototypes
        print("\n[3/7] Building phoneme prototypes...")
        phoneme_prototypes = build_phoneme_prototypes(
            REFERENCE_DIR, PHONEME_DICT, processor, model, device
        )
        
        # Step 4: Load and segment test audio
        print("\n[4/7] Loading and segmenting test audio...")
        if not os.path.exists(TEST_AUDIO_PATH):
            raise Exception(f"Test file '{TEST_AUDIO_PATH}' not found!")
            
        test_audio, sr = librosa.load(TEST_AUDIO_PATH, sr=16000)
        segments = segment_audio(test_audio, sr)
        print(f"Detected {len(segments)} segments")
        
        # Step 5: Match segments to words
        print("\n[5/7] Matching segments to reference words...")
        segment_results = match_segments_to_words(
            test_audio, segments, reference_embeddings, processor, model, device
        )
        
        if not segment_results:
            raise Exception("No valid segments found in audio!")
        
        # Step 6: Analyze specific word (phoneme-level)
        print("\n[6/7] Performing phoneme-level analysis...")
        word_audio = segment_results[0]['audio']
        
        if TEST_WORD not in PHONEME_DICT:
            raise Exception(f"Word '{TEST_WORD}' not in phoneme dictionary!")
        
        scores = analyze_word(
            word_audio, 
            PHONEME_DICT[TEST_WORD], 
            phoneme_prototypes,
            processor,
            model,
            device
        )
        errors = classify_phoneme_errors(scores)
        
        print(f"\nPhoneme Scores for '{TEST_WORD}':")
        for ph, score in scores.items():
            print(f"  {ph}: {score:.3f} ({errors[ph]})")
        
        # Step 7: Save all outputs
        print("\n[7/7] Generating and saving outputs...")
        
        # Save heatmap
        heatmap_path = plot_heatmap(scores, TEST_WORD, OUTPUT_DIR)
        
        # Save text report
        report_path = save_text_report(TEST_WORD, scores, errors, OUTPUT_DIR)
        
        # Save JSON for PHP
        json_path, json_result = save_json_output(TEST_WORD, scores, errors, heatmap_path, report_path, OUTPUT_DIR)
        
        print(f"\n✓ All outputs saved to '{OUTPUT_DIR}/' folder")
        
        # Print JSON result for PHP to capture
        print("\n" + "="*70)
        print("JSON_RESULT_START")
        print(json.dumps(json_result, indent=2))
        print("JSON_RESULT_END")
        print("="*70)
        
        print("\n" + "="*70)
        print("Analysis complete!")
        print("="*70)
        
    except Exception as e:
        # Return error as JSON
        error_result = {
            "status": "error",
            "message": str(e),
            "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }
        print("\nJSON_RESULT_START")
        print(json.dumps(error_result, indent=2))
        print("JSON_RESULT_END")
        sys.exit(1)


if __name__ == "__main__":
    main()