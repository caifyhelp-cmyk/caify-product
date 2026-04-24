import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../services/api_service.dart';

/// 사례형 포스팅 제출 바텀시트
/// 사용법: showCaseSubmitSheet(context)
Future<bool> showCaseSubmitSheet(BuildContext context) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => const _CaseSubmitSheet(),
  );
  return result == true;
}

class _CaseSubmitSheet extends StatefulWidget {
  const _CaseSubmitSheet();

  @override
  State<_CaseSubmitSheet> createState() => _CaseSubmitSheetState();
}

class _CaseSubmitSheetState extends State<_CaseSubmitSheet> {
  final _titleCtrl   = TextEditingController();
  final _contentCtrl = TextEditingController();
  final _picker      = ImagePicker();

  final List<File> _images = [];
  bool _submitting = false;
  String? _error;

  static const _maxImages = 8;
  static const _green = Color(0xFF03C75A);

  @override
  void dispose() {
    _titleCtrl.dispose();
    _contentCtrl.dispose();
    super.dispose();
  }

  // ── 이미지 선택 ───────────────────────────────────────────────
  Future<void> _pickImages() async {
    final remain = _maxImages - _images.length;
    if (remain <= 0) return;

    final picked = await _picker.pickMultiImage(limit: remain);
    if (!mounted || picked.isEmpty) return;
    setState(() {
      for (final xf in picked) {
        if (_images.length < _maxImages) _images.add(File(xf.path));
      }
    });
  }

  Future<void> _pickFromCamera() async {
    if (_images.length >= _maxImages) return;
    final photo = await _picker.pickImage(source: ImageSource.camera);
    if (!mounted || photo == null) return;
    setState(() => _images.add(File(photo.path)));
  }

  void _removeImage(int i) => setState(() => _images.removeAt(i));

  // ── 제출 ─────────────────────────────────────────────────────
  Future<void> _submit() async {
    final title   = _titleCtrl.text.trim();
    final content = _contentCtrl.text.trim();

    if (title.isEmpty) {
      setState(() => _error = '사례명을 입력해주세요.');
      return;
    }
    if (content.isEmpty) {
      setState(() => _error = '사례 내용을 입력해주세요.');
      return;
    }

    setState(() { _submitting = true; _error = null; });

    final res = await ApiService.submitCase(
      caseTitle:  title,
      rawContent: content,
      images:     _images,
    );

    if (!mounted) return;

    if (res['ok'] == true) {
      Navigator.pop(context, true);
    } else {
      setState(() {
        _submitting = false;
        _error = res['error'] as String? ?? '제출 실패';
      });
    }
  }

  // ── 이미지 소스 선택 다이얼로그 ──────────────────────────────
  void _showImageSourceDialog() {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            Container(
              width: 40, height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 16),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined, color: _green),
              title: const Text('갤러리에서 선택'),
              onTap: () { Navigator.pop(context); _pickImages(); },
            ),
            ListTile(
              leading: const Icon(Icons.camera_alt_outlined, color: _green),
              title: const Text('카메라로 촬영'),
              onTap: () { Navigator.pop(context); _pickFromCamera(); },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  // ── UI ───────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

    return DraggableScrollableSheet(
      initialChildSize: 0.92,
      minChildSize: 0.5,
      maxChildSize: 0.97,
      builder: (_, scrollCtrl) => Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        child: Column(
          children: [
            // ── 핸들 + 헤더 ──────────────────────────────────────
            _buildHeader(),
            const Divider(height: 1),

            // ── 스크롤 영역 ──────────────────────────────────────
            Expanded(
              child: ListView(
                controller: scrollCtrl,
                padding: EdgeInsets.fromLTRB(20, 20, 20, bottomInset + 20),
                children: [
                  _buildHint(),
                  const SizedBox(height: 20),
                  _buildTitleField(),
                  const SizedBox(height: 16),
                  _buildContentField(),
                  const SizedBox(height: 20),
                  _buildImageSection(),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    _buildError(),
                  ],
                  const SizedBox(height: 24),
                  _buildSubmitBtn(),
                  const SizedBox(height: 8),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildHeader() => Padding(
    padding: const EdgeInsets.fromLTRB(20, 14, 8, 14),
    child: Row(
      children: [
        Container(
          width: 40, height: 4,
          margin: const EdgeInsets.only(right: 12),
        ),
        const Expanded(
          child: Text(
            '사례 제출',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
          ),
        ),
        IconButton(
          icon: const Icon(Icons.close),
          onPressed: () => Navigator.pop(context),
          visualDensity: VisualDensity.compact,
        ),
      ],
    ),
  );

  Widget _buildHint() => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: const Color(0xFFE8F5E9),
      borderRadius: BorderRadius.circular(10),
    ),
    child: const Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.lightbulb_outline, color: _green, size: 18),
        SizedBox(width: 10),
        Expanded(
          child: Text(
            'AI가 사례 내용을 분석해 블로그 포스팅으로 자동 변환합니다.\n'
            '환자 정보, 시술 과정, 결과를 자세히 적을수록 좋습니다.',
            style: TextStyle(fontSize: 13, color: Color(0xFF2E7D32), height: 1.5),
          ),
        ),
      ],
    ),
  );

  Widget _buildTitleField() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const Text('사례명', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      TextField(
        controller: _titleCtrl,
        textInputAction: TextInputAction.next,
        decoration: InputDecoration(
          hintText: '예) 65세 하악 임플란트 성공 케이스',
          hintStyle: const TextStyle(color: Colors.grey, fontSize: 14),
          filled: true,
          fillColor: const Color(0xFFF8F8F8),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: _green, width: 1.5),
          ),
          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        ),
      ),
    ],
  );

  Widget _buildContentField() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        children: [
          const Text('사례 내용', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const Spacer(),
          ValueListenableBuilder(
            valueListenable: _contentCtrl,
            builder: (_, __, ___) => Text(
              '${_contentCtrl.text.length}자',
              style: const TextStyle(fontSize: 12, color: Colors.grey),
            ),
          ),
        ],
      ),
      const SizedBox(height: 8),
      TextField(
        controller: _contentCtrl,
        minLines: 6,
        maxLines: 12,
        keyboardType: TextInputType.multiline,
        decoration: InputDecoration(
          hintText: '환자 나이, 증상, 시술 내용, 경과, 결과를 자세히 적어주세요.\n\n'
              '예)\n'
              '- 65세 여성, 하악 우측 대구치 결손 2년\n'
              '- 골밀도 양호, CT 판독 후 즉시 식립\n'
              '- 3개월 후 골유착 확인, 최종 보철 완성\n'
              '- 저작 기능 완전 회복, 통증 없음',
          hintStyle: const TextStyle(color: Colors.grey, fontSize: 13, height: 1.6),
          filled: true,
          fillColor: const Color(0xFFF8F8F8),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: _green, width: 1.5),
          ),
          contentPadding: const EdgeInsets.all(14),
        ),
      ),
    ],
  );

  Widget _buildImageSection() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        children: [
          const Text('사진 첨부', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(width: 8),
          Text(
            '${_images.length}/$_maxImages',
            style: const TextStyle(fontSize: 12, color: Colors.grey),
          ),
          const Spacer(),
          const Text('(선택)', style: TextStyle(fontSize: 12, color: Colors.grey)),
        ],
      ),
      const SizedBox(height: 10),
      SizedBox(
        height: 90,
        child: ListView(
          scrollDirection: Axis.horizontal,
          children: [
            // 추가 버튼
            if (_images.length < _maxImages)
              GestureDetector(
                onTap: _showImageSourceDialog,
                child: Container(
                  width: 82,
                  height: 82,
                  margin: const EdgeInsets.only(right: 10),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF0FFF6),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: _green, width: 1.5),
                  ),
                  child: const Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.add_photo_alternate_outlined, color: _green, size: 28),
                      SizedBox(height: 4),
                      Text('사진 추가', style: TextStyle(fontSize: 11, color: _green)),
                    ],
                  ),
                ),
              ),

            // 선택된 이미지들
            ..._images.asMap().entries.map((e) {
              final i = e.key;
              final f = e.value;
              return Stack(
                children: [
                  Container(
                    width: 82,
                    height: 82,
                    margin: const EdgeInsets.only(right: 10),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(10),
                      image: DecorationImage(
                        image: FileImage(f),
                        fit: BoxFit.cover,
                      ),
                    ),
                  ),
                  Positioned(
                    top: 2, right: 12,
                    child: GestureDetector(
                      onTap: () => _removeImage(i),
                      child: Container(
                        width: 22, height: 22,
                        decoration: BoxDecoration(
                          color: Colors.black.withOpacity(0.6),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.close, color: Colors.white, size: 14),
                      ),
                    ),
                  ),
                ],
              );
            }),
          ],
        ),
      ),
    ],
  );

  Widget _buildError() => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: const Color(0xFFFCE8E6),
      borderRadius: BorderRadius.circular(8),
    ),
    child: Text(
      _error!,
      style: const TextStyle(color: Color(0xFFC5221F), fontSize: 13),
    ),
  );

  Widget _buildSubmitBtn() => SizedBox(
    width: double.infinity,
    height: 52,
    child: ElevatedButton(
      onPressed: _submitting ? null : _submit,
      style: ElevatedButton.styleFrom(
        backgroundColor: _green,
        foregroundColor: Colors.white,
        disabledBackgroundColor: Colors.grey.shade300,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        elevation: 0,
      ),
      child: _submitting
          ? const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                SizedBox(
                  width: 18, height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                ),
                SizedBox(width: 12),
                Text('AI에게 전달 중...', style: TextStyle(fontSize: 15)),
              ],
            )
          : const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.send_rounded, size: 18),
                SizedBox(width: 8),
                Text('AI에게 전달하기', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
              ],
            ),
    ),
  );
}
