<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use Exception;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Form;
use ReflectionClass;
use Session;

/**
 * GLPI's native Forms module (Glpi\Form\*, the in-core successor to the
 * Formcreator plugin) — service-catalog-style forms that can be
 * "published" to specific profiles/groups/users and, on submission,
 * automatically create configured destination items (typically a
 * Ticket). Two read-only tools, usable from both GLPI interfaces: which
 * forms a given caller can even see is entirely governed by GLPI's own
 * access-control policies (see assertAnswerable() below), not by the
 * Standard/Self-Service split this plugin uses elsewhere — a form is
 * either published to this person's profile or it isn't.
 *
 * Deliberately verified against the `11.0/bugfixes` branch specifically,
 * not `main` — this module already differs in places between GLPI 11 and
 * the in-development GLPI 12 on `main` (see KnowbaseTools' own docblock
 * for the same lesson learned the hard way on KB categories).
 *
 * A form's questions are dynamic per-form and per-type (25+ distinct
 * QuestionType classes as of this writing, each with its own expected
 * answer shape and its own extra_data config), so this doesn't attempt
 * to hand-translate every type into a strict JSON Schema. Instead
 * glpi_get_form_schema() exposes each question's type name, mandatory
 * flag, and raw extra_data — enough for the calling model to describe the
 * form. Read-only: forms are not submitted through this plugin.
 */
class FormTools
{
    private static function currentAccessParameters(): FormAccessParameters
    {
        $sessionInfo = Session::getCurrentSessionInfo();
        if ($sessionInfo === null) {
            throw new Exception('no active GLPI session', 401);
        }
        return new FormAccessParameters($sessionInfo);
    }

    /**
     * The real gate, not the interface split used elsewhere in this
     * plugin: FormAccessControlManager::canAnswerForm() (verified against
     * src/Glpi/Form/AccessControl/FormAccessControlManager.php) checks the
     * form's own configured access-control policies — which can restrict
     * by profile_ids/group_ids/user_ids (AllowListConfig), by a direct
     * link token, or allow unauthenticated access — and separately
     * confirms the form is active and not deleted.
     */
    private static function assertAnswerable(Form $form): void
    {
        $manager = FormAccessControlManager::getInstance();
        if (!$manager->canAnswerForm($form, self::currentAccessParameters())) {
            throw new Exception("not allowed to answer form {$form->getID()} (not published for this profile, or inactive)", 403);
        }
    }

    public static function listForms(array $args): array
    {
        $manager      = FormAccessControlManager::getInstance();
        $accessParams = self::currentAccessParameters();
        $limit        = min((int) ($args['limit'] ?? 20), 100);

        $out = [];
        foreach ((new Form())->find(['is_active' => 1]) as $row) {
            $form = new Form();
            if (!$form->getFromDB($row['id'])) {
                continue;
            }
            if (!$manager->canAnswerForm($form, $accessParams)) {
                continue;
            }
            $out[] = [
                'id'          => (int) $form->fields['id'],
                'name'        => $form->fields['name'],
                'description' => trim(strip_tags($form->fields['description'] ?? '')),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Returns every question across every section, in order, with enough
     * per-question metadata (type, mandatory, extra_data) for the calling
     * model to explain each question to the person without needing this
     * plugin to special-case all 25+ question types.
     */
    public static function getFormSchema(array $args): array
    {
        $formId = (int) $args['form_id'];
        $form   = new Form();
        if (!$form->getFromDB($formId)) {
            throw new Exception("form $formId not found", 404);
        }
        self::assertAnswerable($form);

        $sections = [];
        foreach ($form->getSections() as $section) {
            $questions = [];
            foreach ($section->getQuestions() as $question) {
                $type      = $question->getQuestionType();
                $extraData = $question->getExtraDataConfig();

                $questions[] = [
                    'id'           => (int) $question->getID(),
                    'name'         => $question->fields['name'],
                    'description'  => $question->fields['description'] ?? null,
                    'type'         => $type !== null ? (new ReflectionClass($type))->getShortName() : null,
                    'is_mandatory' => (bool) $question->fields['is_mandatory'],
                    'extra_data'   => $extraData instanceof JsonFieldInterface ? $extraData->jsonSerialize() : null,
                ];
            }
            $sections[] = [
                'id'        => (int) $section->getID(),
                'name'      => $section->fields['name'] ?? null,
                'questions' => $questions,
            ];
        }

        return [
            'id'          => (int) $form->fields['id'],
            'name'        => $form->fields['name'],
            'description' => trim(strip_tags($form->fields['description'] ?? '')),
            'sections'    => $sections,
        ];
    }
}
